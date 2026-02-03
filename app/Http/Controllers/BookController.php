<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\BookTitle;
use App\Models\BookAsset;
use App\Models\LibrarySetting;
use App\Services\GoogleBooksService;
use App\Http\Requests\StoreBookTitleRequest;
use App\Http\Requests\UpdateBookTitleRequest;

class BookController extends Controller
{
    // 1. GET ALL BOOKS (Public Catalog)
    public function index()
    {
        // Get books with a count of how many are 'available'
        return BookTitle::withCount([
            'assets as available_copies' => function ($query) {
                $query->where('status', 'available');
            }
        ])->get();
    }

    // 2. SEARCH BOOKS
    public function search($keyword)
    {
        return BookTitle::where('title', 'like', "%$keyword%")
            ->orWhere('author', 'like', "%$keyword%")
            ->orWhere('category', 'like', "%$keyword%")
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
        $latestAsset = BookAsset::where('asset_code', 'like', $prefix . '%')
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
     * Generate the next accession number for book titles.
     * Format: LIB-YYYY-XXXX (e.g., LIB-2026-0001)
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function getNextAccession()
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

        // Format with leading zeros (4 digits)
        $accessionNumber = $prefix . str_pad($nextSequence, 4, '0', STR_PAD_LEFT);

        return response()->json([
            'accession_number' => $accessionNumber,
            'sequence' => $nextSequence,
            'year' => $year
        ]);
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
        $fields = $request->validate([
            'title' => 'required|string|max:255',
            'subtitle' => 'nullable|string|max:255',
            'author' => 'required|string|max:255',
            'category' => 'required|string|max:100',
            'isbn' => 'nullable|string|max:50',
            'lccn' => 'nullable|string|max:50',
            'issn' => 'nullable|string|max:50',
            'publisher' => 'nullable|string|max:255',
            'place_of_publication' => 'nullable|string|max:255',
            'published_year' => 'nullable|integer|min:1800|max:' . (date('Y') + 1),
            'copyright_year' => 'nullable|integer|min:1800|max:' . (date('Y') + 1),
            'call_number' => 'nullable|string|max:100',
            'physical_description' => 'nullable|string',
            'pages' => 'nullable|integer|min:1',
            'edition' => 'nullable|string|max:50',
            'series' => 'nullable|string|max:255',
            'volume' => 'nullable|string|max:50',
            'price' => 'nullable|numeric|min:0',
            'book_penalty' => 'nullable|numeric|min:0',
            'language' => 'nullable|string|max:50',
            'description' => 'nullable|string',
            'location' => 'nullable|string|max:255',
            'copies' => 'nullable|integer|min:1|max:100',
            'accession_no' => 'nullable|string|max:50', // Renamed from accession_number to match table
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120' // 5MB max
        ]);

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
            $createdAssets = [];

            // Get the base accession number from request or generate one
            $baseAccession = $request->input('accession_no');

            for ($i = 0; $i < $copies; $i++) {
                if ($i === 0 && $baseAccession && !BookAsset::where('asset_code', $baseAccession)->exists()) {
                    // Use the provided accession number for the first copy
                    $assetCode = $baseAccession;
                } else {
                    // Generate sequential asset code for additional copies
                    $assetCode = $this->generateAssetBarcode();
                }

                // Ensure uniqueness
                while (BookAsset::where('asset_code', $assetCode)->exists()) {
                    $assetCode = $this->generateAssetBarcode();
                }

                $asset = BookAsset::create([
                    'book_title_id' => $bookTitle->id,
                    'asset_code' => $assetCode,
                    'building' => null, // Default location from book title
                    'aisle' => null,
                    'shelf' => null,
                    'status' => 'available'
                ]);
                $createdAssets[] = $asset;
            }

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

    // 4. ADD PHYSICAL COPY TO SHELF (Admin Only)
    public function storeAsset(Request $request)
    {
        $fields = $request->validate([
            'book_title_id' => 'required|exists:book_titles,id',
            'building' => 'nullable|string',
            'aisle' => 'nullable|string',
            'shelf' => 'nullable|string'
        ]);

        // Auto-generate the asset barcode
        $assetCode = $this->generateAssetBarcode();
        $fields['asset_code'] = $assetCode;
        $fields['status'] = 'available';

        return BookAsset::create($fields);
    }
    // NEW: Dashboard Statistics
    public function dashboardStats()
    {
        $totalTitles = \App\Models\BookTitle::count();
        $totalCopies = \App\Models\BookAsset::count();

        // Count active transactions (where 'returned_at' is null)
        $activeLoans = \App\Models\Transaction::whereNull('returned_at')->count();

        // Count overdue loans
        $overdueLoans = \App\Models\Transaction::whereNull('returned_at')
            ->where('due_date', '<', now())
            ->count();

        // Count total students (users who are NOT 'admin')
        $totalStudents = \App\Models\User::where('role', '!=', 'admin')->count();

        // Financial Stats
        $totalFines = \App\Models\Transaction::sum('penalty_amount');
        $collectedFines = \App\Models\Transaction::where('payment_status', 'paid')->sum('penalty_amount');

        return response()->json([
            'titles' => $totalTitles,
            'copies' => $totalCopies,
            'loans' => $activeLoans,
            'overdue' => $overdueLoans,
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

        $fields = $request->validate([
            'title' => 'required|string|max:255|unique:book_titles,title,' . $id,
            'subtitle' => 'nullable|string|max:255',
            'author' => 'required|string|max:255',
            'category' => 'required|string|max:100',
            'isbn' => 'nullable|string|max:50',
            'lccn' => 'nullable|string|max:50',
            'issn' => 'nullable|string|max:50',
            'publisher' => 'nullable|string|max:255',
            'place_of_publication' => 'nullable|string|max:255',
            'published_year' => 'nullable|integer|min:1800|max:' . (date('Y') + 1),
            'copyright_year' => 'nullable|integer|min:1800|max:' . (date('Y') + 1),
            'call_number' => 'nullable|string|max:100',
            'physical_description' => 'nullable|string',
            'pages' => 'nullable|integer|min:1',
            'edition' => 'nullable|string|max:50',
            'series' => 'nullable|string|max:255',
            'volume' => 'nullable|string|max:50',
            'price' => 'nullable|numeric|min:0',
            'book_penalty' => 'nullable|numeric|min:0',
            'language' => 'nullable|string|max:50',
            'description' => 'nullable|string',
            'location' => 'nullable|string|max:255',
            'accession_no' => 'nullable|string|max:50',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120'
        ]);

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

        $book->update($fields);
        return response()->json($book);
    }

    // DELETE a book
    public function destroy($id)
    {
        $book = BookTitle::find($id);
        if (!$book)
            return response()->json(['message' => 'Not found'], 404);

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
        $limit = $request->query('limit', 12); // Default 12 items

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

    // GET BORROWED BOOKS (for return dropdown)
    public function getBorrowedBooks()
    {
        $borrowedBooks = BookAsset::where('status', 'borrowed')
            ->whereHas('bookTitle') // Only include assets with non-deleted book titles
            ->with(['bookTitle:id,title,author,image_path'])
            ->orderBy('asset_code')
            ->get()
            ->map(function ($asset) {
                // Get the active transaction for this book
                $transaction = \App\Models\Transaction::where('book_asset_id', $asset->id)
                    ->whereNull('returned_at')
                    ->with('user:id,name,student_id')
                    ->first();

                return [
                    'asset_code' => $asset->asset_code,
                    'status' => $asset->status, // Added status field
                    'title' => $asset->bookTitle->title ?? 'Unknown',
                    'subtitle' => $asset->bookTitle->subtitle ?? null, // Added subtitle
                    'author' => $asset->bookTitle->author ?? 'Unknown',
                    'image_path' => $asset->bookTitle->image_path ?? null,
                    'borrower' => $transaction->user->name ?? 'Unknown',
                    'student_id' => $transaction->user->student_id ?? 'N/A',
                    'due_date' => $transaction->due_date ?? null,
                    'is_overdue' => $transaction && $transaction->due_date ? now()->gt($transaction->due_date) : false
                ];
            });

        return response()->json($borrowedBooks);
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
            ->sum('penalty_amount');

        $activeLoans = \App\Models\Transaction::where('user_id', $student->id)
            ->whereNull('returned_at')
            ->count();

        // Dynamic settings from LibrarySetting (same for all students)
        $maxLoans = LibrarySetting::getMaxLoansPerStudent();
        $loanDays = LibrarySetting::getDefaultLoanDays();
        $finePerDay = LibrarySetting::getFinePerDay();

        return response()->json([
            'student_id' => $student->student_id,
            'name' => $student->name,
            'course' => $student->course,
            'year_level' => $student->year_level,
            'section' => $student->section,
            'pending_fines' => $pendingFines,
            'active_loans' => $activeLoans,
            'max_loans' => $maxLoans,
            'loan_days' => $loanDays,
            'fine_per_day' => $finePerDay,
            'is_cleared' => $pendingFines == 0 && $activeLoans < $maxLoans,
            'block_reason' => $pendingFines > 0 ? 'Pending fines: ₱' . number_format($pendingFines, 2) :
                ($activeLoans >= $maxLoans ? "Max {$maxLoans} books reached" : null)
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

            // If book is borrowed, get borrower info
            if ($bookAsset->status === 'borrowed') {
                $transaction = \App\Models\Transaction::where('book_asset_id', $bookAsset->id)
                    ->whereNull('returned_at')
                    ->with('user:id,name,student_id,course')
                    ->first();

                if ($transaction) {
                    $response['borrower'] = [
                        'name' => $transaction->user->name,
                        'student_id' => $transaction->user->student_id,
                        'course' => $transaction->user->course
                    ];
                    $response['due_date'] = $transaction->due_date;
                    $response['is_overdue'] = now()->gt($transaction->due_date);
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
                $transaction = \App\Models\Transaction::where('book_asset_id', $borrowedAsset->id)
                    ->whereNull('returned_at')
                    ->with('user:id,name,student_id,course')
                    ->first();

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

                if ($transaction) {
                    $response['borrower'] = [
                        'name' => $transaction->user->name,
                        'student_id' => $transaction->user->student_id,
                        'course' => $transaction->user->course
                    ];
                    $response['due_date'] = $transaction->due_date;
                    $response['is_overdue'] = now()->gt($transaction->due_date);
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
            'transactions' => function ($q) {
                $q->latest()->limit(1); // Get the transaction that marked it lost
            }
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

        // Check for unpaid fines associated with this asset
        $latestTransaction = $asset->transactions()->latest()->first();

        // If there is a transaction with a penalty that hasn't been paid/waived
        if ($latestTransaction && $latestTransaction->penalty_amount > 0 && $latestTransaction->payment_status === 'pending') {
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
}