<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\BookTitle;
use App\Models\BookAsset;

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

    // 3. CREATE NEW BOOK TITLE (Admin Only)
    public function storeTitle(Request $request)
    {
        $fields = $request->validate([
            'title' => 'required|string',
            'author' => 'required|string',
            'category' => 'required|string',
            'isbn' => 'nullable|string'
        ]);

        return BookTitle::create($fields);
    }

    // 4. ADD PHYSICAL COPY TO SHELF (Admin Only)
    public function storeAsset(Request $request)
    {
        $fields = $request->validate([
            'book_title_id' => 'required|exists:book_titles,id',
            'building' => 'required|string',
            'aisle' => 'required|string',
            'shelf' => 'required|string'
        ]);

        // Auto-generate the asset barcode
        $assetCode = $this->generateAssetBarcode();
        $fields['asset_code'] = $assetCode;

        return BookAsset::create($fields);
    }
    // NEW: Dashboard Statistics
    public function dashboardStats()
    {
        $totalTitles = \App\Models\BookTitle::count();
        $totalCopies = \App\Models\BookAsset::count();
        // Count active transactions (where 'returned_at' is null)
        $activeLoans = \App\Models\Transaction::whereNull('returned_at')->count();
        // Count total students (users who are NOT 'admin')
        $totalStudents = \App\Models\User::where('role', '!=', 'admin')->count();

        return response()->json([
            'titles' => $totalTitles,
            'copies' => $totalCopies,
            'loans' => $activeLoans,
            'students' => $totalStudents
        ]);
    }
    // UPDATE an existing book
    public function update(Request $request, $id)
    {
        $book = BookTitle::find($id);
        if (!$book)
            return response()->json(['message' => 'Not found'], 404);

        $request->validate([
            'title' => 'required',
            'author' => 'required',
            'category' => 'required'
        ]);

        $book->update($request->all());
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
                    'author' => $book->author,
                    'category' => $book->category,
                    'cover_image' => $book->cover_image, // Make sure this is the full URL or relative path handled by frontend
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
            ->with('bookTitle:id,title,author,category')
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
                    'title' => $asset->bookTitle->title ?? 'Unknown',
                    'author' => $asset->bookTitle->author ?? 'Unknown',
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
            ->with(['bookTitle:id,title,author'])
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
                    'title' => $asset->bookTitle->title ?? 'Unknown',
                    'author' => $asset->bookTitle->author ?? 'Unknown',
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

        $loanDays = $this->getCategoryForCourse($student->course) ?
            ($student->course === 'Maritime' ? 1 : 7) : 7;

        return response()->json([
            'student_id' => $student->student_id,
            'name' => $student->name,
            'course' => $student->course,
            'year_level' => $student->year_level,
            'section' => $student->section,
            'pending_fines' => $pendingFines,
            'active_loans' => $activeLoans,
            'loan_days' => $student->course === 'Maritime' ? 1 : 7,
            'is_cleared' => $pendingFines == 0 && $activeLoans < 3,
            'block_reason' => $pendingFines > 0 ? 'Pending fines: ₱' . number_format($pendingFines, 2) :
                ($activeLoans >= 3 ? 'Max 3 books reached' : null)
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
            // Found by asset_code - return full asset details
            $response = [
                'found' => true,
                'asset_code' => $bookAsset->asset_code,
                'status' => $bookAsset->status,
                'title' => $bookAsset->bookTitle->title ?? 'Unknown',
                'author' => $bookAsset->bookTitle->author ?? 'Unknown',
                'category' => $bookAsset->bookTitle->category ?? 'Unknown',
                'location' => $bookAsset->building . ' - ' . $bookAsset->aisle . ' - ' . $bookAsset->shelf,
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
                    'location' => $availableAsset->building . ' - ' . $availableAsset->aisle . ' - ' . $availableAsset->shelf,
                    'borrower' => null,
                    'due_date' => null,
                    'is_overdue' => false
                ]);
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
                'isbn' => $bookTitle->isbn,
                'location' => 'N/A - No physical copies registered',
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
}