<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\BookTitle;
use App\Models\BookAsset;
use App\Models\LibrarySetting;
use App\Services\GoogleBooksService;
use App\Http\Requests\StoreBookTitleRequest;
use App\Http\Requests\UpdateBookTitleRequest;
use Carbon\Carbon;

class BookController extends Controller
{
    // 1. GET ALL BOOKS (Public Catalog)
    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 20);

        // Get books with counts for each status — paginated
        return BookTitle::withCount([
            'assets as available_copies' => function ($query) {
                $query->where('status', 'available');
            },
            'assets as borrowed_copies' => function ($query) {
                $query->where('status', 'borrowed');
            },
            'assets as damaged_copies' => function ($query) {
                $query->where('status', 'damaged');
            },
            'assets as lost_copies' => function ($query) {
                $query->where('status', 'lost');
            },
            'assets as total_copies'
        ])->orderBy('title')->paginate($perPage);
    }

    // 2. SEARCH BOOKS
    public function search($keyword)
    {
        // Escape SQL wildcard characters to prevent wildcard injection
        // Without this, a user could send "%" to enumerate all records
        // or use "_" patterns to probe data structure
        $sanitized = addcslashes($keyword, '%_');

        return BookTitle::where('title', 'like', "%{$sanitized}%")
            ->orWhere('author', 'like', "%{$sanitized}%")
            ->orWhere('category', 'like', "%{$sanitized}%")
            ->with('assets') // Include the physical copies in results
            ->get();
    }

    /**
     * Lookup book information by ISBN using Google Books API
     * 
     * @param string $isbn The ISBN to lookup
     * @return \Illuminate\Http\JsonResponse
     */
    public function lookupIsbn($isbn)
    {
        $googleBooks = new GoogleBooksService();
        $bookData = $googleBooks->lookupByIsbn($isbn);

        if (!$bookData) {
            return response()->json([
                'found' => false,
                'message' => 'No book found for ISBN: ' . $isbn
            ], 404);
        }

        return response()->json($bookData);
    }

    /**
     * Generate the next sequential asset barcode.
     * Format: BOOK-YYYY-XXXX (e.g., BOOK-2026-0001)
     */
    private function generateAssetBarcode(): string
    {
        $year = date('Y');
        $prefix = 'BOOK-' . $year . '-';

        // Find the latest asset_code for the current year
        $latestAsset = BookAsset::withTrashed()
            ->where('asset_code', 'like', $prefix . '%')
            ->orderBy('asset_code', 'desc')
            ->first();

        if ($latestAsset) {
            // Extract the sequence number and increment
            $lastSequence = (int) substr($latestAsset->asset_code, strlen($prefix));
            $nextSequence = $lastSequence + 1;
        } else {
            $nextSequence = 1;
        }

        // Format with leading zeros (4 digits)
        return $prefix . str_pad($nextSequence, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Internal helper: compute the next available accession number string.
     * Format: LIB-YYYY-XXXX (e.g., LIB-2026-0001)
     *
     * Checks both LIB- and legacy BOOK- prefixes to avoid collisions.
     *
     * @return string  The next accession number ready to be assigned.
     */
    private function getNextAccessionBase(): string
    {
        $year = date('Y');
        $prefix = 'LIB-' . $year . '-';

        // Find the latest accession number for the current year from book_assets
        $latestAsset = BookAsset::where('asset_code', 'like', 'LIB-' . $year . '-%')
            ->orderBy('asset_code', 'desc')
            ->first();

        // Also check BOOK- prefix for backwards compatibility
        $latestBookAsset = BookAsset::where('asset_code', 'like', 'BOOK-' . $year . '-%')
            ->orderBy('asset_code', 'desc')
            ->first();

        $nextSequence = 1;

        if ($latestAsset) {
            $lastSequence = (int) substr($latestAsset->asset_code, strlen($prefix));
            $nextSequence = max($nextSequence, $lastSequence + 1);
        }

        if ($latestBookAsset) {
            $bookPrefix = 'BOOK-' . $year . '-';
            $lastBookSequence = (int) substr($latestBookAsset->asset_code, strlen($bookPrefix));
            $nextSequence = max($nextSequence, $lastBookSequence + 1);
        }

        return $prefix . str_pad($nextSequence, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Public API endpoint: return the next accession number.
     * Delegates to getNextAccessionBase().
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getNextAccession()
    {
        $accessionNumber = $this->getNextAccessionBase();

        // Extract sequence and year for the JSON response
        $year = date('Y');
        $prefix = 'LIB-' . $year . '-';
        $sequence = (int) substr($accessionNumber, strlen($prefix));

        return response()->json([
            'accession_number' => $accessionNumber,
            'sequence' => $sequence,
            'year' => $year
        ]);
    }

    /**
     * Check if one or more accession numbers are already in use.
     *
     * Checks against both book_titles.accession_no and book_assets.asset_code.
     * Supports single check (?accession_no=X) and batch check (?batch=X,Y,Z).
     * Pass ?exclude_book_id=N to ignore the current book during edits.
     *
     * @param  Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function checkAccession(Request $request)
    {
        $excludeBookId = $request->input('exclude_book_id');

        // --- Batch mode: check multiple accession numbers at once ---
        if ($request->filled('batch')) {
            $codes = array_filter(
                array_map('trim', explode(',', $request->input('batch')))
            );

            $results = [];
            foreach ($codes as $code) {
                $results[$code] = $this->isAccessionAvailable($code, $excludeBookId);
            }

            return response()->json(['results' => $results]);
        }

        // --- Single mode ---
        $code = $request->input('accession_no');
        if (!$code) {
            return response()->json(['available' => true, 'conflict_type' => null]);
        }

        $check = $this->isAccessionAvailable($code, $excludeBookId);

        return response()->json($check);
    }

    /**
     * Internal helper: determine if a single accession number is available.
     *
     * @param  string      $code
     * @param  int|null    $excludeBookId  Book-title ID to ignore (edit mode)
     * @return array{available: bool, conflict_type: string|null}
     */
    private function isAccessionAvailable(string $code, $excludeBookId = null): array
    {
        // 1. Check book_titles.accession_no (soft-delete aware)
        $titleQuery = BookTitle::where('accession_no', $code)->whereNull('deleted_at');
        if ($excludeBookId) {
            $titleQuery->where('id', '!=', $excludeBookId);
        }
        if ($titleQuery->exists()) {
            return ['available' => false, 'conflict_type' => 'title'];
        }

        // 2. Check book_assets.asset_code (include trashed to be safe)
        $assetQuery = BookAsset::withTrashed()->where('asset_code', $code);
        if ($excludeBookId) {
            $assetQuery->where('book_title_id', '!=', $excludeBookId);
        }
        if ($assetQuery->exists()) {
            return ['available' => false, 'conflict_type' => 'asset'];
        }

        return ['available' => true, 'conflict_type' => null];
    }

    /**
     * Generate a random 12-digit barcode number.
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function generateRandomBarcode()
    {
        // Generate a unique 12-digit number
        $barcode = str_pad(mt_rand(0, 999999999999), 12, '0', STR_PAD_LEFT);

        // Verify uniqueness
        while (BookAsset::where('asset_code', $barcode)->exists()) {
            $barcode = str_pad(mt_rand(0, 999999999999), 12, '0', STR_PAD_LEFT);
        }

        return response()->json([
            'barcode' => $barcode
        ]);
    }

    // 3. CREATE NEW BOOK TITLE (Admin Only)
    public function storeTitle(StoreBookTitleRequest $request)
    {
        $fields = $request->validated();

        // Case-insensitive duplicate title check
        $existingBook = BookTitle::whereRaw('LOWER(title) = ?', [strtolower($fields['title'])])->first();
        if ($existingBook) {
            return response()->json([
                'message' => 'A book with this title already exists.',
                'errors' => ['title' => ['A book with this title already exists (case-insensitive).']]
            ], 422);
        }

        try {
            // Handle image upload
            $imagePath = null;
            if ($request->hasFile('image')) {
                $image = $request->file('image');
                $filename = time() . '_' . uniqid() . '.' . $image->getClientOriginalExtension();

                // Ensure directory exists
                $uploadPath = public_path('uploads/books');
                if (!file_exists($uploadPath)) {
                    mkdir($uploadPath, 0755, true);
                }

                $image->move($uploadPath, $filename);
                $imagePath = 'uploads/books/' . $filename;
            }

            // Auto-generate ISBN if not provided
            $isbn = $fields['isbn'] ?? null;
            if (empty($isbn)) {
                do {
                    $isbn = (string) mt_rand(1000000000000, 9999999999999);
                } while (BookTitle::where('isbn', $isbn)->exists());
            }

            // Create the book title
            $bookTitle = BookTitle::create([
                'title' => $fields['title'],
                'subtitle' => $fields['subtitle'] ?? null,
                'author' => $fields['author'],
                'category' => $fields['category'],
                'isbn' => $isbn,

                'lccn' => $fields['lccn'] ?? null,
                'issn' => $fields['issn'] ?? null,
                'publisher' => $fields['publisher'] ?? null,
                'place_of_publication' => $fields['place_of_publication'] ?? null,
                'published_year' => $fields['published_year'] ?? null,
                'copyright_year' => $fields['copyright_year'] ?? null,
                'call_number' => $fields['call_number'] ?? null,
                'physical_description' => $fields['physical_description'] ?? null,
                'pages' => $fields['pages'] ?? null,

                'edition' => $fields['edition'] ?? null,
                'series' => $fields['series'] ?? null,
                'volume' => $fields['volume'] ?? null,
                'price' => $fields['price'] ?? null,
                'book_penalty' => $fields['book_penalty'] ?? null,
                'language' => $fields['language'] ?? null,
                'description' => $fields['description'] ?? null,
                'location' => $fields['location'] ?? null,
                'accession_no' => $fields['accession_no'] ?? null,
                'image_path' => $imagePath
            ]);

            // Auto-generate physical copies (BookAsset records)
            $copies = isset($fields['copies']) ? (int) $fields['copies'] : 0;
            $baseAccession = $request->input('accession_no');
            $initialStatus = $request->boolean('is_damaged') ? 'damaged' : 'available';

            $createdAssets = $this->generateBookAssets($bookTitle, $copies, $baseAccession, $fields['location'] ?? null, $initialStatus);

            // Load the assets relationship for response
            $bookTitle->load('assets');

            return response()->json([
                'message' => 'Book created successfully',
                'book' => $bookTitle,
                'copies_created' => count($createdAssets)
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create book. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Helper to generate book assets.
     * Can be used for creating new books or adding copies to existing ones.
     */
    private function generateBookAssets($bookTitle, $count, $baseAccession = null, $location = null, $status = 'available')
    {
        $createdAssets = [];

        for ($i = 0; $i < $count; $i++) {
            if ($baseAccession) {
                // Smart Increment Logic for Accession Number
                if ($i === 0) {
                    $assetCode = $baseAccession;
                } else {
                    // Try to increment the last number found in the string
                    if (preg_match('/^(.*?)(\d+)$/', $baseAccession, $matches)) {
                        $prefix = $matches[1];
                        $number = $matches[2];
                        $length = strlen($number);
                        $newNumber = (int) $number + $i;
                        $assetCode = $prefix . str_pad($newNumber, $length, '0', STR_PAD_LEFT);
                    } else {
                        // If no number to increment, append -Sequence
                        $assetCode = $baseAccession . '-' . ($i + 1);
                    }
                }

                // Strict check: If generated accession exists, avoid collision
                // For bulk creation, skipping might be safer than erroring out the whole batch,
                // but let's stick to the original logic which throws an error to notify user.
                if (BookAsset::withTrashed()->where('asset_code', $assetCode)->exists()) {
                    // If collision happens during loop, we stop and return what we have so far?
                    // Or throw exception to trigger catch block?
                    // Let's throw an exception to be caught by the controller
                    throw new \Exception("Accession number $assetCode already exists.");
                }

            } else {
                // No Accession Number provided -> Generate sequential asset code (BOOK-YYYY-XXXX)
                $assetCode = $this->generateAssetBarcode();

                // Generate Unique
                while (BookAsset::withTrashed()->where('asset_code', $assetCode)->exists()) {
                    $assetCode = $this->generateAssetBarcode();
                }
            }

            $asset = BookAsset::create([
                'book_title_id' => $bookTitle->id,
                'asset_code' => $assetCode,
                'building' => 'Main Library', // Default building
                'aisle' => null,
                'shelf' => $location, // Map location input to shelf
                'status' => $status
            ]);
            $createdAssets[] = $asset;
        }

        return $createdAssets;
    }


    public function dashboardStats()
    {
        $totalTitles = \App\Models\BookTitle::count();
        // Physical Copies Breakdown
        $physicalCopiesStats = \App\Models\BookAsset::selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // Ensure all keys exist
        $availableCopies = $physicalCopiesStats['available'] ?? 0;
        $borrowedCopies = $physicalCopiesStats['borrowed'] ?? 0;
        $damagedCopies = $physicalCopiesStats['damaged'] ?? 0;
        $lostCopies = $physicalCopiesStats['lost'] ?? 0;

        // Recalculate total to be safe (or just use sum)
        $totalCopies = $availableCopies + $borrowedCopies + $damagedCopies + $lostCopies;

        // Count active transactions (where 'returned_at' is null)
        $studentActiveLoans = \App\Models\Transaction::whereNull('returned_at')->count();
        $facultyActiveLoans = \App\Models\FacultyTransaction::whereNull('returned_at')->count();

        // Format: "Student / Faculty"
        $formattedLoans = "{$studentActiveLoans} / {$facultyActiveLoans}";

        // Count active transactions breakdown
        $loansBreakdown = [
            'student' => $studentActiveLoans,
            'faculty' => $facultyActiveLoans
        ];

        // Count overdue loans (Student + Faculty)
        $studentOverdue = \App\Models\Transaction::whereNull('returned_at')
            ->where('due_date', '<', now())
            ->count();

        $facultyOverdue = \App\Models\FacultyTransaction::whereNull('returned_at')
            ->where('due_date', '<', now())
            ->count();

        $totalOverdue = $studentOverdue + $facultyOverdue;

        // Count total students (users who are NOT 'admin')
        $totalStudents = \App\Models\User::where('role', '!=', 'admin')->count();

        // Financial Stats
        $totalFines = \App\Models\Transaction::sum('penalty_amount') + \App\Models\FacultyTransaction::sum('penalty_amount');
        $collectedFines = \App\Models\Transaction::where('payment_status', 'paid')->sum('penalty_amount') +
            \App\Models\FacultyTransaction::where('payment_status', 'paid')->sum('penalty_amount');

        return response()->json([
            'titles' => $totalTitles,
            'copies' => $totalCopies,
            'copies_breakdown' => [
                'available' => $availableCopies,
                'borrowed' => $borrowedCopies,
                'damaged' => $damagedCopies,
                'lost' => $lostCopies
            ],
            'loans' => $formattedLoans,
            'loans_breakdown' => $loansBreakdown,
            'overdue' => $totalOverdue,
            'students' => $totalStudents,
            'total_fines' => $totalFines,
            'collected_fines' => $collectedFines
        ]);
    }
    // UPDATE an existing book
    public function update(UpdateBookTitleRequest $request, $id)
    {
        $book = BookTitle::find($id);
        if (!$book)
            return response()->json(['message' => 'Not found'], 404);

        $fields = $request->validated();

        // Handle image upload
        if ($request->hasFile('image')) {
            $image = $request->file('image');
            $filename = time() . '_' . uniqid() . '.' . $image->getClientOriginalExtension();

            // Ensure directory exists
            $uploadPath = public_path('uploads/books');
            if (!file_exists($uploadPath)) {
                mkdir($uploadPath, 0755, true);
            }

            // Delete old image if exists
            if ($book->image_path && file_exists(public_path($book->image_path))) {
                unlink(public_path($book->image_path));
            }

            $image->move($uploadPath, $filename);
            $fields['image_path'] = 'uploads/books/' . $filename;
        }

        // Remove 'image' from fields as it's handled separately
        unset($fields['image']);
        // Remove copy-related fields — these are NOT columns on book_titles
        unset($fields['added_copies']);
        unset($fields['is_damaged_copies']);
        unset($fields['new_copies_accession']);

        $book->update($fields);

        // Handle adding new copies
        $addedCopiesCount = 0;
        $assignedAccessions = [];
        try {
            if ($request->filled('added_copies') && (int) $request->input('added_copies') > 0) {
                $count = (int) $request->input('added_copies');
                $status = $request->boolean('is_damaged_copies') ? 'damaged' : 'available';

                // Use user-provided accession number if supplied;
                // otherwise auto-generate the next LIB-YYYY-XXXX
                $baseAccession = $request->filled('new_copies_accession')
                    ? trim($request->input('new_copies_accession'))
                    : $this->getNextAccessionBase();

                $createdAssets = $this->generateBookAssets($book, $count, $baseAccession, $book->location, $status);
                $addedCopiesCount = $count;
                $assignedAccessions = array_map(fn($a) => $a->asset_code, $createdAssets);
            }
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Book details updated, but failed to add copies: ' . $e->getMessage(),
                'book' => $book,
                'added_copies' => 0
            ], 500);
        }

        return response()->json([
            'message' => 'Book updated successfully' . ($addedCopiesCount > 0 ? " ($addedCopiesCount copies added)" : ''),
            'book' => $book,
            'added_copies' => $addedCopiesCount,
            'assigned_accessions' => $assignedAccessions
        ]);
    }

    // DELETE a book
    public function destroy($id)
    {
        $book = BookTitle::with('assets')->find($id);
        if (!$book)
            return response()->json(['message' => 'Not found'], 404);

        // Create a transaction record for each asset to log the deletion
        foreach ($book->assets as $asset) {
            // Rename the asset code to free it up for reuse
            // Appends -DEL-{timestamp} to avoid unique constraint violations
            $originalCode = $asset->asset_code;
            $asset->asset_code = $originalCode . '-DEL-' . time();
            $asset->save();

            \App\Models\Transaction::create([
                'user_id' => auth()->id(), // Admin who deleted it
                'book_asset_id' => $asset->id,
                'borrowed_at' => now(),
                'due_date' => now(), // No due date really
                'returned_at' => now(), // Immediately "returned/completed"
                'processed_by' => auth()->id(),
                'penalty_amount' => 0,
                'payment_status' => 'paid', // Or 'waived', just to not show as fine
                'remarks' => 'Book Deleted'
            ]);

            // Also delete the asset to keep things clean (optional but recommended)
            $asset->delete();
        }

        $book->delete();
        return response()->json(['message' => 'Deleted successfully']);
    }

    // Course to Category Mapping for prioritization
    private function getCategoryForCourse($course)
    {
        // Since Category is now "Resource Type" (Book, Map, etc.) instead of Subject,
        // we cannot recommend books based on Course -> Subject mapping anymore.
        // Returning empty array effectively disables the "Recommended" badge logic.
        return [];

        /* Old Subject Mapping (Disabled)
        $mapping = [
            'BSIT' => ['Information Technology', 'Computer Science', 'Programming', 'Technology'],
            'BSED' => ['Education', 'Teaching', 'Pedagogy', 'Child Development'],
            'BEED' => ['Education', 'Elementary', 'Teaching', 'Child Development'],
            'Maritime' => ['Maritime', 'Engineering', 'Seafaring', 'Navigation'],
            'BSHM' => ['Hospitality', 'Hotel Management', 'Tourism', 'Food Service'],
            'BS Criminology' => ['Criminology', 'Law', 'Criminal Justice', 'Forensics'],
            'BSBA' => ['Business', 'Accounting', 'Management', 'Finance'],
            'BS Tourism' => ['Tourism', 'Hospitality', 'Travel', 'Culture']
        ];
        return $mapping[$course] ?? [];
        */
    }

    // NEW: Get books for Dashboard Grid (recent available ones)
    public function getDashboardBooks(Request $request)
    {
        $limit = $request->query('limit', 6); // Default 6 items (Top 6 newest)

        // Get distinct book titles that have at least one available copy
        $books = BookTitle::whereHas('assets', function ($query) {
            $query->where('status', 'available');
        })
            ->withCount([
                'assets as available_copies' => function ($query) {
                    $query->where('status', 'available');
                }
            ])
            ->latest() // Most recently added titles first
            ->take($limit)
            ->get()
            ->map(function ($book) {
                return [
                    'id' => $book->id,
                    'title' => $book->title,
                    'subtitle' => $book->subtitle, // Added subtitle
                    'author' => $book->author,
                    'category' => $book->category,
                    'publisher' => $book->publisher,
                    'image_path' => $book->image_path,
                    'cover_image' => $book->cover_image, // Legacy support
                    'available_copies' => $book->available_copies
                ];
            });

        return response()->json($books);
    }

    // GET AVAILABLE BOOKS (for borrowing dropdown) - With Major Prioritization
    public function getAvailableBooks(Request $request)
    {
        $course = $request->query('course');
        $relevantCategories = $this->getCategoryForCourse($course);

        $availableBooks = BookAsset::where('status', 'available')
            ->whereHas('bookTitle') // Only include assets with non-deleted book titles
            ->with('bookTitle:id,title,author,category,image_path')
            ->orderBy('asset_code')
            ->get()
            ->map(function ($asset) use ($relevantCategories) {
                $category = $asset->bookTitle->category ?? '';
                $isRelevant = false;

                foreach ($relevantCategories as $rc) {
                    if (stripos($category, $rc) !== false) {
                        $isRelevant = true;
                        break;
                    }
                }

                return [
                    'asset_code' => $asset->asset_code,
                    'status' => $asset->status, // Added status field
                    'title' => $asset->bookTitle->title ?? 'Unknown',
                    'subtitle' => $asset->bookTitle->subtitle ?? null, // Added subtitle
                    'author' => $asset->bookTitle->author ?? 'Unknown',
                    'image_path' => $asset->bookTitle->image_path ?? null,
                    'category' => $category,
                    'location' => $asset->building . ' - ' . $asset->aisle . ' - ' . $asset->shelf,
                    'is_recommended' => $isRelevant
                ];
            })
            ->sortByDesc('is_recommended')
            ->values();

        return response()->json($availableBooks);
    }

    /**
     * GET AVAILABLE BOOKS — Paginated Catalog for Browse Modal.
     * Returns ONE entry per BookTitle with available copy count,
     * instead of listing every individual copy separately.
     * Supports server-side search, category filter, and pagination.
     */
    public function getAvailableBooksPagedCatalog(Request $request)
    {
        $perPage = min((int) $request->input('per_page', 18), 100);
        $search = $request->input('search');
        $category = $request->input('category');

        $query = BookTitle::withCount([
            'assets as available_copies' => function ($q) {
                $q->where('status', 'available');
            },
            'assets as total_copies',
        ])
            ->having('available_copies', '>', 0);

        // Server-side search across title, author, ISBN, call number
        if ($search) {
            $sanitized = addcslashes($search, '%_');
            $query->where(function ($q) use ($sanitized) {
                $q->where('title', 'like', "%{$sanitized}%")
                    ->orWhere('author', 'like', "%{$sanitized}%")
                    ->orWhere('isbn', 'like', "%{$sanitized}%")
                    ->orWhere('call_number', 'like', "%{$sanitized}%");
            });
        }

        // Server-side category filter
        if ($category && $category !== 'All') {
            $query->where('category', $category);
        }

        $paginated = $query->orderBy('title')->paginate($perPage);

        // Transform: attach the first available asset_code for auto-selection
        $paginated->getCollection()->transform(function ($bookTitle) {
            $firstAvailable = $bookTitle->assets()
                ->where('status', 'available')
                ->orderBy('asset_code')
                ->first();

            return [
                'id' => $bookTitle->id,
                'title' => $bookTitle->title,
                'subtitle' => $bookTitle->subtitle,
                'author' => $bookTitle->author,
                'isbn' => $bookTitle->isbn,
                'call_number' => $bookTitle->call_number,
                'category' => $bookTitle->category ?? '',
                'image_path' => $bookTitle->image_path,
                'available_copies' => $bookTitle->available_copies,
                'total_copies' => $bookTitle->total_copies,
                'asset_code' => $firstAvailable->asset_code ?? null,
                'status' => 'available',
            ];
        });

        // Get distinct categories for the filter pills
        $categories = BookTitle::whereHas('assets', function ($q) {
            $q->where('status', 'available');
        })
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->prepend('All')
            ->values();

        return response()->json([
            'books' => $paginated,
            'categories' => $categories,
        ]);
    }

    // GET BORROWED BOOKS (for return dropdown)
    public function getBorrowedBooks(Request $request)
    {
        $type = $request->query('type'); // 'student', 'faculty', or null (both)

        $query = BookAsset::where('status', 'borrowed')
            ->whereHas('bookTitle'); // Only include assets with non-deleted book titles

        // Filter based on requested type
        if ($type === 'student') {
            $query->whereHas('transactions', function ($q) {
                $q->whereNull('returned_at');
            });
        } elseif ($type === 'faculty') {
            $query->whereHas('facultyTransactions', function ($q) {
                $q->whereNull('returned_at');
            });
        } else {
            // Default: Include books that have EITHER active student OR faculty transaction
            $query->where(function ($q) {
                $q->whereHas('transactions', function ($sq) {
                    $sq->whereNull('returned_at');
                })->orWhereHas('facultyTransactions', function ($fq) {
                    $fq->whereNull('returned_at');
                });
            });
        }

        $borrowedBooks = $query->with([
            'bookTitle:id,title,author,image_path',
            'transactions' => function ($query) {
                $query->whereNull('returned_at')
                    ->with('user:id,name,student_id');
            },
            'facultyTransactions' => function ($query) {
                $query->whereNull('returned_at')
                    ->with('faculty:id,faculty_id,name');
            }
        ])
            ->orderBy('asset_code')
            ->get()
            ->map(function ($asset) use ($type) {
                // If type is specifically 'student', only return if student transaction exists
                if ($type === 'student') {
                    $transaction = $asset->transactions->first();
                    if ($transaction && $transaction->user) {
                        return [
                            'asset_code' => $asset->asset_code,
                            'status' => $asset->status,
                            'title' => $asset->bookTitle->title ?? 'Unknown',
                            'subtitle' => $asset->bookTitle->subtitle ?? null,
                            'author' => $asset->bookTitle->author ?? 'Unknown',
                            'image_path' => $asset->bookTitle->image_path ?? null,
                            'borrower' => $transaction->user->name,
                            'student_id' => $transaction->user->student_id ?? 'N/A',
                            'type' => 'Student',
                            'due_date' => $transaction->due_date ?? null,
                            'is_overdue' => $transaction->due_date ? now()->gt($transaction->due_date) : false
                        ];
                    }
                    return null; // Should not happen given query constraints, but safe guard
                }

                // If type is specifically 'faculty', only return if faculty transaction exists
                if ($type === 'faculty') {
                    $facultyTrans = $asset->facultyTransactions->first();
                    if ($facultyTrans && $facultyTrans->faculty) {
                        return [
                            'asset_code' => $asset->asset_code,
                            'status' => $asset->status,
                            'title' => $asset->bookTitle->title ?? 'Unknown',
                            'subtitle' => $asset->bookTitle->subtitle ?? null,
                            'author' => $asset->bookTitle->author ?? 'Unknown',
                            'image_path' => $asset->bookTitle->image_path ?? null,
                            'borrower' => $facultyTrans->faculty->name,
                            'student_id' => $facultyTrans->faculty->faculty_id ?? 'N/A',
                            'type' => 'Faculty',
                            'due_date' => $facultyTrans->due_date ?? null,
                            'is_overdue' => $facultyTrans->due_date ? now()->gt($facultyTrans->due_date) : false
                        ];
                    }
                    return null;
                }

                // Fallback (Mixed/No Type): Try student first, then faculty
                $transaction = $asset->transactions->first();

                if ($transaction && $transaction->user) {
                    return [
                        'asset_code' => $asset->asset_code,
                        'status' => $asset->status,
                        'title' => $asset->bookTitle->title ?? 'Unknown',
                        'subtitle' => $asset->bookTitle->subtitle ?? null,
                        'author' => $asset->bookTitle->author ?? 'Unknown',
                        'image_path' => $asset->bookTitle->image_path ?? null,
                        'borrower' => $transaction->user->name,
                        'student_id' => $transaction->user->student_id ?? 'N/A',
                        'type' => 'Student',
                        'due_date' => $transaction->due_date ?? null,
                        'is_overdue' => $transaction->due_date ? now()->gt($transaction->due_date) : false
                    ];
                }

                // Try faculty transaction
                $facultyTrans = $asset->facultyTransactions->first();

                if ($facultyTrans && $facultyTrans->faculty) {
                    return [
                        'asset_code' => $asset->asset_code,
                        'status' => $asset->status,
                        'title' => $asset->bookTitle->title ?? 'Unknown',
                        'subtitle' => $asset->bookTitle->subtitle ?? null,
                        'author' => $asset->bookTitle->author ?? 'Unknown',
                        'image_path' => $asset->bookTitle->image_path ?? null,
                        'borrower' => $facultyTrans->faculty->name,
                        'student_id' => $facultyTrans->faculty->faculty_id ?? 'N/A',
                        'type' => 'Faculty',
                        'due_date' => $facultyTrans->due_date ?? null,
                        'is_overdue' => $facultyTrans->due_date ? now()->gt($facultyTrans->due_date) : false
                    ];
                }

                return null;
            })
            ->filter() // Remove nulls
            ->values();

        return response()->json($borrowedBooks);
    }

    /**
     * GET BORROWED BOOKS — Paginated Catalog for Return Modal.
     * Supports server-side search and pagination for the return book selector.
     */
    public function getBorrowedBooksPagedCatalog(Request $request)
    {
        $perPage = min((int) $request->input('per_page', 18), 100);
        $search = $request->input('search');
        $type = $request->input('type', 'student'); // 'student' or 'faculty'

        $query = BookAsset::where('status', 'borrowed')
            ->whereHas('bookTitle');

        // Type-specific filtering
        if ($type === 'student') {
            $query->whereHas('transactions', function ($q) {
                $q->whereNull('returned_at');
            });
        } elseif ($type === 'faculty') {
            $query->whereHas('facultyTransactions', function ($q) {
                $q->whereNull('returned_at');
            });
        }

        // Server-side search across title, author, asset_code, and borrower name
        if ($search) {
            $sanitized = addcslashes($search, '%_');
            $query->where(function ($q) use ($sanitized, $type) {
                $q->where('asset_code', 'like', "%{$sanitized}%")
                    ->orWhereHas('bookTitle', function ($bq) use ($sanitized) {
                        $bq->where('title', 'like', "%{$sanitized}%")
                            ->orWhere('author', 'like', "%{$sanitized}%");
                    });

                // Also search by borrower name
                if ($type === 'student') {
                    $q->orWhereHas('transactions', function ($tq) use ($sanitized) {
                        $tq->whereNull('returned_at')
                            ->whereHas('user', function ($uq) use ($sanitized) {
                                $uq->where('name', 'like', "%{$sanitized}%");
                            });
                    });
                } elseif ($type === 'faculty') {
                    $q->orWhereHas('facultyTransactions', function ($tq) use ($sanitized) {
                        $tq->whereNull('returned_at')
                            ->whereHas('faculty', function ($fq) use ($sanitized) {
                                $fq->where('name', 'like', "%{$sanitized}%");
                            });
                    });
                }
            });
        }

        $paginated = $query->with([
            'bookTitle:id,title,author,image_path,category',
            'transactions' => function ($q) {
                $q->whereNull('returned_at')->with('user:id,name,student_id');
            },
            'facultyTransactions' => function ($q) {
                $q->whereNull('returned_at')->with('faculty:id,faculty_id,name');
            }
        ])
            ->orderBy('asset_code')
            ->paginate($perPage);

        // Transform paginated data
        $paginated->getCollection()->transform(function ($asset) use ($type) {
            $borrower = 'Unknown';
            $studentId = 'N/A';
            $dueDate = null;
            $isOverdue = false;

            if ($type === 'student') {
                $tx = $asset->transactions->first();
                if ($tx && $tx->user) {
                    $borrower = $tx->user->name;
                    $studentId = $tx->user->student_id ?? 'N/A';
                    $dueDate = $tx->due_date;
                    $isOverdue = $tx->due_date ? now()->gt($tx->due_date) : false;
                }
            } else {
                $tx = $asset->facultyTransactions->first();
                if ($tx && $tx->faculty) {
                    $borrower = $tx->faculty->name;
                    $studentId = $tx->faculty->faculty_id ?? 'N/A';
                    $dueDate = $tx->due_date;
                    $isOverdue = $tx->due_date ? now()->gt($tx->due_date) : false;
                }
            }

            return [
                'asset_code' => $asset->asset_code,
                'status' => $asset->status,
                'title' => $asset->bookTitle->title ?? 'Unknown',
                'author' => $asset->bookTitle->author ?? 'Unknown',
                'image_path' => $asset->bookTitle->image_path ?? null,
                'category' => $asset->bookTitle->category ?? '',
                'borrower' => $borrower,
                'student_id' => $studentId,
                'type' => $type === 'student' ? 'Student' : 'Faculty',
                'due_date' => $dueDate,
                'is_overdue' => $isOverdue,
            ];
        });

        return response()->json([
            'books' => $paginated,
        ]);
    }

    // CHECK STUDENT CLEARANCE (for borrowing validation)
    public function checkClearance($studentId)
    {
        $student = \App\Models\User::where('student_id', $studentId)->first();

        if (!$student) {
            return response()->json(['message' => 'Student not found'], 404);
        }

        $pendingFines = \App\Models\Transaction::where('user_id', $student->id)
            ->where('payment_status', 'pending')
            ->where('penalty_amount', '>', 0)
            ->sum('penalty_amount');

        $activeLoans = \App\Models\Transaction::where('user_id', $student->id)
            ->whereNull('returned_at')
            ->count();

        // Dynamic settings from LibrarySetting (same for all students)
        $maxLoans = LibrarySetting::getMaxLoansPerStudent();
        $loanDays = LibrarySetting::getDefaultLoanDays();
        $finePerDay = LibrarySetting::getFinePerDay();

        // Check if student has any lost books that are still pending payment
        $hasLostBooks = \App\Models\Transaction::where('user_id', $student->id)
            ->where('payment_status', 'pending')
            ->where('penalty_amount', '>', 0)
            ->whereHas('bookAsset', function ($q) {
                $q->where('status', 'lost');
            })
            ->exists();

        // Calculate accrued fines for overdue unreturned books
        $overdueTransactions = \App\Models\Transaction::where('user_id', $student->id)
            ->whereNull('returned_at')
            ->where('due_date', '<', Carbon::today())
            ->with(['bookAsset.bookTitle'])
            ->get();

        $accruedFines = 0;
        $overdueDetails = [];
        foreach ($overdueTransactions as $tx) {
            $daysOverdue = (int) Carbon::parse($tx->due_date)->startOfDay()->diffInDays(Carbon::today());
            $fine = $daysOverdue * $finePerDay;
            $accruedFines += $fine;
            $overdueDetails[] = [
                'transaction_id' => $tx->id,
                'book_title' => $tx->bookAsset->bookTitle->title ?? 'Unknown',
                'asset_code' => $tx->bookAsset->asset_code ?? 'N/A',
                'due_date' => $tx->due_date,
                'days_overdue' => $daysOverdue,
                'accrued_fine' => (float) $fine,
            ];
        }

        $overdueCount = count($overdueTransactions);
        $totalOwed = (float) $pendingFines + $accruedFines;

        // Cleared only if: no fines (pending or accrued), no overdue, under loan limit, no lost books
        $isCleared = $totalOwed == 0 && $overdueCount == 0 && $activeLoans < $maxLoans && !$hasLostBooks;

        // Only set block_reason if student is NOT cleared
        $blockReason = null;
        if (!$isCleared) {
            if ($hasLostBooks) {
                $blockReason = 'Student has a LOST BOOK pending payment.';
            } elseif ($overdueCount > 0) {
                $blockReason = "Student has {$overdueCount} overdue book(s). Accruing fine: \u20b1" . number_format($accruedFines, 2);
            } elseif ($pendingFines > 0) {
                $blockReason = 'Pending fines: \u20b1' . number_format($pendingFines, 2);
            } elseif ($activeLoans >= $maxLoans) {
                $blockReason = "Max {$maxLoans} books reached";
            }
        }

        return response()->json([
            'student_id' => $student->student_id,
            'name' => $student->name,
            'course' => $student->course,
            'year_level' => $student->year_level,
            'section' => $student->section,
            'pending_fines' => (float) $pendingFines,
            'accrued_fines' => (float) $accruedFines,
            'total_owed' => (float) $totalOwed,
            'overdue_count' => $overdueCount,
            'overdue_details' => $overdueDetails,
            'active_loans' => $activeLoans,
            'max_loans' => $maxLoans,
            'loan_days' => $loanDays,
            'fine_per_day' => $finePerDay,
            'is_cleared' => $isCleared,
            'block_reason' => $blockReason
        ]);
    }

    /**
     * Lookup a book by barcode for instant scanning
     * Returns book details, availability, and current borrower if applicable
     * Searches both BookAsset (by asset_code) and BookTitle (by ISBN)
     */
    public function lookup($barcode)
    {
        // First, try to find by asset_code in BookAsset
        $bookAsset = BookAsset::where('asset_code', $barcode)
            ->with('bookTitle')
            ->first();

        if ($bookAsset) {
            $bookTitle = $bookAsset->bookTitle;

            // Found by asset_code - return full asset details
            $response = [
                'found' => true,
                'asset_code' => $bookAsset->asset_code,
                'status' => $bookAsset->status,
                'title' => $bookTitle->title ?? 'Unknown',
                'subtitle' => $bookTitle->subtitle ?? null, // Added subtitle
                'author' => $bookTitle->author ?? 'Unknown',
                'category' => $bookTitle->category ?? 'Unknown',
                'publisher' => $bookTitle->publisher ?? null,
                'published_year' => $bookTitle->published_year ?? null,
                'call_number' => $bookTitle->call_number ?? null,
                'pages' => $bookTitle->pages ?? null,
                'language' => $bookTitle->language ?? null,
                'description' => $bookTitle->description ?? null,
                'image_path' => $bookTitle->image_path ?? null,
                'isbn' => $bookTitle->isbn ?? null,
                'location' => trim($bookAsset->building . ' - ' . $bookAsset->aisle . ' - ' . $bookAsset->shelf, ' -') ?: ($bookTitle->location ?? 'N/A'),
                'borrower' => null,
                'due_date' => null,
                'is_overdue' => false
            ];

            if ($bookAsset->status === 'borrowed') {
                $transaction = \App\Models\Transaction::where('book_asset_id', $bookAsset->id)
                    ->whereNull('returned_at')
                    ->with('user:id,name,student_id,course')
                    ->first();

                if ($transaction) {
                    $response['borrower'] = [
                        'name' => $transaction->user->name,
                        'student_id' => $transaction->user->student_id,
                        'course' => $transaction->user->course,
                        'type' => 'Student'
                    ];
                    $response['due_date'] = $transaction->due_date;
                    $response['is_overdue'] = now()->gt($transaction->due_date);
                } else {
                    // Try Faculty Transaction
                    $facultyTrans = \App\Models\FacultyTransaction::where('book_asset_id', $bookAsset->id)
                        ->whereNull('returned_at')
                        ->with('faculty')
                        ->first();

                    if ($facultyTrans) {
                        $response['borrower'] = [
                            'name' => $facultyTrans->faculty->name,
                            'student_id' => $facultyTrans->faculty->faculty_id,
                            'course' => $facultyTrans->faculty->department,
                            'type' => 'Faculty'
                        ];
                        $response['due_date'] = $facultyTrans->due_date;
                        $response['is_overdue'] = $facultyTrans->due_date ? now()->gt($facultyTrans->due_date) : false;
                    }
                }
            }

            return response()->json($response);
        }

        // Second, try to find by ISBN in BookTitle (for newly registered books without physical copies)
        $bookTitle = BookTitle::where('isbn', $barcode)->first();

        if ($bookTitle) {
            // Found by ISBN - check if it has any physical copies
            $availableAsset = BookAsset::where('book_title_id', $bookTitle->id)
                ->where('status', 'available')
                ->first();

            if ($availableAsset) {
                // Has available copy - return that asset
                return response()->json([
                    'found' => true,
                    'asset_code' => $availableAsset->asset_code,
                    'status' => $availableAsset->status,
                    'title' => $bookTitle->title,
                    'author' => $bookTitle->author,
                    'category' => $bookTitle->category,
                    'publisher' => $bookTitle->publisher,
                    'published_year' => $bookTitle->published_year,
                    'call_number' => $bookTitle->call_number,
                    'pages' => $bookTitle->pages,
                    'language' => $bookTitle->language,
                    'description' => $bookTitle->description,
                    'image_path' => $bookTitle->image_path,
                    'isbn' => $bookTitle->isbn,
                    'location' => trim($availableAsset->building . ' - ' . $availableAsset->aisle . ' - ' . $availableAsset->shelf, ' -') ?: ($bookTitle->location ?? 'N/A'),
                    'borrower' => null,
                    'due_date' => null,
                    'is_overdue' => false
                ]);
            }

            // Check for borrowed copies (important for Return Scanner!)
            $borrowedAsset = BookAsset::where('book_title_id', $bookTitle->id)
                ->where('status', 'borrowed')
                ->first();

            if ($borrowedAsset) {
                // Has a borrowed copy - return that asset with borrower info
                $response = [
                    'found' => true,
                    'asset_code' => $borrowedAsset->asset_code,
                    'status' => 'borrowed',
                    'title' => $bookTitle->title,
                    'author' => $bookTitle->author,
                    'category' => $bookTitle->category,
                    'publisher' => $bookTitle->publisher,
                    'published_year' => $bookTitle->published_year,
                    'call_number' => $bookTitle->call_number,
                    'pages' => $bookTitle->pages,
                    'language' => $bookTitle->language,
                    'description' => $bookTitle->description,
                    'image_path' => $bookTitle->image_path,
                    'isbn' => $bookTitle->isbn,
                    'location' => trim($borrowedAsset->building . ' - ' . $borrowedAsset->aisle . ' - ' . $borrowedAsset->shelf, ' -') ?: ($bookTitle->location ?? 'N/A'),
                    'borrower' => null,
                    'due_date' => null,
                    'is_overdue' => false
                ];

                $transaction = \App\Models\Transaction::where('book_asset_id', $borrowedAsset->id)
                    ->whereNull('returned_at')
                    ->with('user:id,name,student_id,course')
                    ->first();

                if ($transaction) {
                    $response['borrower'] = [
                        'name' => $transaction->user->name,
                        'student_id' => $transaction->user->student_id,
                        'course' => $transaction->user->course,
                        'type' => 'Student'
                    ];
                    $response['due_date'] = $transaction->due_date;
                    $response['is_overdue'] = now()->gt($transaction->due_date);
                } else {
                    // Try Faculty Transaction
                    $facultyTrans = \App\Models\FacultyTransaction::where('book_asset_id', $borrowedAsset->id)
                        ->whereNull('returned_at')
                        ->with('faculty')
                        ->first();

                    if ($facultyTrans) {
                        $response['borrower'] = [
                            'name' => $facultyTrans->faculty->name,
                            'student_id' => $facultyTrans->faculty->faculty_id,
                            'course' => $facultyTrans->faculty->department,
                            'type' => 'Faculty'
                        ];
                        $response['due_date'] = $facultyTrans->due_date;
                        $response['is_overdue'] = $facultyTrans->due_date ? now()->gt($facultyTrans->due_date) : false;
                    }
                }

                return response()->json($response);
            }

            // Book title exists but no physical copies yet
            return response()->json([
                'found' => true,
                'needs_physical_copy' => true,
                'book_title_id' => $bookTitle->id,
                'status' => 'no_copies',
                'title' => $bookTitle->title,
                'author' => $bookTitle->author,
                'category' => $bookTitle->category,
                'publisher' => $bookTitle->publisher,
                'published_year' => $bookTitle->published_year,
                'call_number' => $bookTitle->call_number,
                'pages' => $bookTitle->pages,
                'language' => $bookTitle->language,
                'description' => $bookTitle->description,
                'image_path' => $bookTitle->image_path,
                'isbn' => $bookTitle->isbn,
                'location' => $bookTitle->location ?? 'N/A - No physical copies registered',
                'borrower' => null,
                'due_date' => null,
                'is_overdue' => false,
                'message' => 'This book is registered but has no physical copies. Please add a physical copy first.'
            ]);
        }

        // Not found anywhere
        return response()->json([
            'found' => false,
            'scanned_code' => $barcode,
            'message' => 'Book not found with barcode: ' . $barcode
        ], 404);
    }

    /**
     * Get all books marked as lost, including their latest transaction state (for payment check).
     */
    public function getLostBooks()
    {
        $lostAssets = BookAsset::with([
            'bookTitle',
            'latestTransaction.user' // Properly eager load the single latest transaction with its user
        ])
            ->where('status', 'lost')
            ->get();

        return response()->json($lostAssets);
    }

    /**
     * Restore a lost book (mark as available).
     * Requires the fine to be paid or waived first.
     */
    public function restoreBook($id)
    {
        $asset = BookAsset::find($id);

        if (!$asset) {
            return response()->json(['message' => 'Book copy not found'], 404);
        }

        if ($asset->status !== 'lost') {
            return response()->json(['message' => 'Book is not marked as lost.'], 400);
        }

        // Check for unpaid fines associated with this asset
        $latestTransaction = $asset->transactions()->latest()->first();

        // If there is a transaction with a penalty that hasn't been paid/waived
        // STRICT CHECK: The PAYMENT STATUS must be 'paid' or 'waived'
        if (
            $latestTransaction && $latestTransaction->penalty_amount > 0 &&
            !in_array($latestTransaction->payment_status, ['paid', 'waived'])
        ) {

            return response()->json([
                'message' => 'Cannot restore book. The lost book fine must be settled first.',
                'error' => 'unpaid_fine',
                'transaction_id' => $latestTransaction->id
            ], 403);
        }

        $asset->status = 'available';
        $asset->save();

        return response()->json(['message' => 'Book restored successfully']);
    }

    /**
     * Get all books marked as damaged.
     */
    public function getDamagedBooks()
    {
        $damagedAssets = BookAsset::with(['bookTitle'])
            ->where('status', 'damaged')
            ->get();

        return response()->json($damagedAssets);
    }

    /**
     * Mark a book asset as damaged.
     * Accepts either asset ID in URL or asset_code in request body.
     */
    public function markAsDamaged(Request $request, $id = null)
    {
        // Try to find asset by ID first, then by asset_code
        $asset = null;

        if ($id) {
            $asset = BookAsset::find($id);
        }

        if (!$asset && $request->has('asset_code')) {
            $asset = BookAsset::where('asset_code', $request->asset_code)->first();
        }

        if (!$asset) {
            return response()->json(['message' => 'Book copy not found'], 404);
        }

        // Only available books can be marked as damaged
        if ($asset->status !== 'available') {
            return response()->json([
                'message' => 'Only available books can be marked as damaged. Current status: ' . $asset->status
            ], 400);
        }

        $asset->status = 'damaged';
        $asset->save();

        return response()->json([
            'message' => 'Book marked as damaged successfully',
            'new_status' => $asset->status
        ]);
    }

    /**
     * Repair a damaged book (mark as available).
     */
    public function repairBook($id)
    {
        $asset = BookAsset::find($id);

        if (!$asset) {
            return response()->json(['message' => 'Book copy not found'], 404);
        }

        if ($asset->status !== 'damaged') {
            return response()->json([
                'message' => 'This book is not marked as damaged. Current status: ' . $asset->status
            ], 400);
        }

        $asset->status = 'available';
        $asset->save();

        return response()->json(['message' => 'Book repaired and restored to inventory successfully']);
    }

    /**
     * Get category summary with book counts.
     * Returns all unique categories with total books and available count.
     */
    public function getCategorySummary()
    {
        // 1. Title counts per category (1 query)
        $categories = BookTitle::selectRaw('
            category,
            COUNT(*) as total_books
        ')
            ->groupBy('category')
            ->orderBy('category')
            ->get();

        // 2. Available titles per category — titles that have at least one available asset (1 query)
        $availableTitles = BookTitle::whereHas('assets', function ($q) {
            $q->where('status', 'available');
        })
            ->selectRaw('category, COUNT(*) as cnt')
            ->groupBy('category')
            ->pluck('cnt', 'category');

        // 3. Asset counts per category — total and available copies (1 query using join)
        $assetCounts = BookAsset::join('book_titles', 'book_assets.book_title_id', '=', 'book_titles.id')
            ->selectRaw('book_titles.category, COUNT(*) as total_copies, SUM(CASE WHEN book_assets.status = ? THEN 1 ELSE 0 END) as available_copies', ['available'])
            ->groupBy('book_titles.category')
            ->get()
            ->keyBy('category');

        // Map results — no extra queries
        $result = $categories->map(function ($cat) use ($availableTitles, $assetCounts) {
            $categoryKey = $cat->category;
            $assetData = $assetCounts->get($categoryKey);

            return [
                'category' => $categoryKey ?: 'Uncategorized',
                'total_books' => $cat->total_books,
                'available_titles' => (int) ($availableTitles[$categoryKey] ?? 0),
                'total_copies' => (int) ($assetData->total_copies ?? 0),
                'available_copies' => (int) ($assetData->available_copies ?? 0)
            ];
        });

        return response()->json($result);
    }

    /**
     * Get paginated books by category.
     * @param string $category The category to filter by
     * @param Request $request For pagination (page, per_page) and search
     */
    public function getBooksByCategory(Request $request, $category)
    {
        $perPage = $request->input('per_page', 20);
        $search = $request->input('search', '');

        $query = BookTitle::where('category', $category)
            ->with([
                'assets' => function ($q) {
                    $q->orderBy('asset_code');
                }
            ])
            ->withCount([
                'assets as available_copies' => function ($query) {
                    $query->where('status', 'available');
                },
                'assets as borrowed_copies' => function ($query) {
                    $query->where('status', 'borrowed');
                },
                'assets as damaged_copies' => function ($query) {
                    $query->where('status', 'damaged');
                },
                'assets as lost_copies' => function ($query) {
                    $query->where('status', 'lost');
                },
                'assets as total_copies'
            ]);

        // Apply search filter if provided
        if ($search) {
            $sanitizedSearch = addcslashes($search, '%_');
            $query->where(function ($q) use ($sanitizedSearch) {
                $q->where('title', 'like', "%{$sanitizedSearch}%")
                    ->orWhere('author', 'like', "%{$sanitizedSearch}%")
                    ->orWhere('isbn', 'like', "%{$sanitizedSearch}%")
                    ->orWhere('call_number', 'like', "%{$sanitizedSearch}%");
            });
        }

        $books = $query->orderBy('title')->paginate($perPage);

        return response()->json($books);
    }
}
