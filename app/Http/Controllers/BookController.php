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
            'asset_code' => 'required|unique:book_assets',
            'building' => 'required|string',
            'aisle' => 'required|string',
            'shelf' => 'required|string'
        ]);

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

    // GET AVAILABLE BOOKS (for borrowing dropdown)
    public function getAvailableBooks()
    {
        $availableBooks = BookAsset::where('status', 'available')
            ->with('bookTitle:id,title,author')
            ->orderBy('asset_code')
            ->get()
            ->map(function ($asset) {
                return [
                    'asset_code' => $asset->asset_code,
                    'title' => $asset->bookTitle->title ?? 'Unknown',
                    'author' => $asset->bookTitle->author ?? 'Unknown',
                    'location' => $asset->building . ' - ' . $asset->aisle . ' - ' . $asset->shelf
                ];
            });

        return response()->json($availableBooks);
    }

    // GET BORROWED BOOKS (for return dropdown)
    public function getBorrowedBooks()
    {
        $borrowedBooks = BookAsset::where('status', 'borrowed')
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
}