<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Transaction;
use App\Models\BookAsset;
use App\Models\User;
use App\Models\LibrarySetting;
use App\Models\MonthlyStatistic;
use Carbon\Carbon;

class TransactionController extends Controller
{
    // Get loan days from settings (same for all students)
    private function getLoanDays(): int
    {
        return LibrarySetting::getDefaultLoanDays();
    }

    // Get max loans per student from settings
    private function getMaxLoansPerStudent(): int
    {
        return LibrarySetting::getMaxLoansPerStudent();
    }

    // Get fine per day from settings
    private function getFinePerDay(): float
    {
        return LibrarySetting::getFinePerDay();
    }

    // 1. BORROW A BOOK
    public function borrow(Request $request)
    {
        // 1. Validate
        $request->validate([
            'student_id' => 'required|exists:users,student_id',
            'asset_code' => 'required|exists:book_assets,asset_code'
        ]);

        try {
            // 2. Find Student and Book
            $student = \App\Models\User::where('student_id', $request->student_id)->first();
            $bookAsset = \App\Models\BookAsset::where('asset_code', $request->asset_code)->first();

            // 3. CLEARANCE CHECK: Block if student has pending fines
            $pendingFines = Transaction::where('user_id', $student->id)
                ->where('payment_status', 'pending')
                ->sum('penalty_amount');

            if ($pendingFines > 0) {
                return response()->json([
                    'message' => 'Student has pending fines of ₱' . number_format($pendingFines, 2) . '. Please settle before borrowing.',
                    'blocked' => true,
                    'pending_fines' => $pendingFines
                ], 403);
            }

            // 4. Check if student already has too many books (dynamic limit from settings)
            $maxLoans = $this->getMaxLoansPerStudent();
            $activeLoans = Transaction::where('user_id', $student->id)
                ->whereNull('returned_at')
                ->count();

            if ($activeLoans >= $maxLoans) {
                return response()->json([
                    'message' => "Student has reached the maximum limit of {$maxLoans} active loans.",
                    'blocked' => true,
                    'max_loans' => $maxLoans,
                    'current_loans' => $activeLoans
                ], 403);
            }

            // 5. Check if Book is available
            if ($bookAsset->status !== 'available') {
                return response()->json(['message' => 'Book is already borrowed!'], 400);
            }

            // 6. Calculate due date (same for all students)
            $loanDays = $this->getLoanDays();
            $dueDate = Carbon::now()->addDays($loanDays);

            // 7. Create Transaction
            $transaction = Transaction::create([
                'user_id' => $student->id,
                'book_asset_id' => $bookAsset->id,
                'borrowed_at' => Carbon::now(),
                'due_date' => $dueDate,
                'processed_by' => $request->user()?->id
            ]);

            // 8. Update Book Status
            $bookAsset->update(['status' => 'borrowed']);

            // 9. STATISTICAL RECORDING: Increment count for call number range
            $callNumber = $bookAsset->bookTitle->call_number ?? null;
            if ($callNumber) {
                MonthlyStatistic::incrementForCallNumber($callNumber, 'student');
            }

            return response()->json([
                'message' => 'Success! Book borrowed.',
                'data' => $transaction,
                'loan_days' => $loanDays,
                'due_date' => $dueDate->format('Y-m-d'),
                'course' => $student->course
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to process borrow request. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    public function returnBook(Request $request)
    {
        $request->validate([
            'asset_code' => 'required|exists:book_assets,asset_code'
        ]);

        try {
            // 1. Find the Book
            $bookAsset = \App\Models\BookAsset::where('asset_code', $request->asset_code)->first();

            // 2. Find the Active Transaction
            $transaction = \App\Models\Transaction::where('book_asset_id', $bookAsset->id)
                ->whereNull('returned_at')
                ->first();

            if (!$transaction) {
                return response()->json(['message' => 'This book is not currently borrowed!'], 400);
            }

            // 3. Check for Late Return (Strict Day Comparison)
            $now = Carbon::now();
            $dueDate = Carbon::parse($transaction->due_date)->endOfDay(); // End of due date (23:59:59)

            $penalty = 0;
            $daysLate = 0;

            // If 'Now' is strictly after Due Date
            if ($now->gt($dueDate)) {
                // Calculate full days difference
                // We use startOfDay to compare "dates" regardless of "time"
                $diffInDays = $dueDate->startOfDay()->diffInDays($now->startOfDay());
                $daysLate = $diffInDays;

                $finePerDay = $this->getFinePerDay(); // Dynamic rate from settings
                $penalty = $daysLate * $finePerDay;
            }

            // 4. Update the Record
            $transaction->update([
                'returned_at' => Carbon::now(),
                'penalty_amount' => $penalty,
                'payment_status' => $penalty > 0 ? 'pending' : 'paid'
            ]);

            // 5. Make the book available again
            $bookAsset->update(['status' => 'available']);

            // 6. Send the result back to the Frontend
            return response()->json([
                'message' => 'Book returned successfully',
                'days_late' => $daysLate,
                'penalty' => $penalty,
                'fine_per_day' => $penalty > 0 ? $this->getFinePerDay() : 0,
                'transaction' => $transaction->load(['user', 'bookAsset.bookTitle'])
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to process return. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    // NEW: Mark a book as lost
    public function markAsLost(Request $request)
    {
        $request->validate([
            'asset_code' => 'required|exists:book_assets,asset_code'
        ]);

        // 1. Find the Book
        $bookAsset = \App\Models\BookAsset::where('asset_code', $request->asset_code)->first();

        // 2. Find the Active Transaction
        $transaction = \App\Models\Transaction::where('book_asset_id', $bookAsset->id)
            ->whereNull('returned_at')
            ->first();

        if (!$transaction) {
            return response()->json(['message' => 'This book is not currently borrowed!'], 400);
        }

        // 3. Calculate Fine (Price of the book)
        $bookPrice = $bookAsset->bookTitle->price;
        $penalty = $bookPrice > 0 ? $bookPrice : 500.00; // Default to 500 if no price set

        // 4. Update the Record to "Lost" state (we treat it as returned but with lost flag if we had one, 
        //    for now we just close the transaction and apply full price penalty)
        $transaction->update([
            'returned_at' => Carbon::now(), // Technically "returned" from circulation
            'penalty_amount' => $penalty,
            'payment_status' => 'pending'
        ]);

        // 5. Update Book Status to 'lost'
        $bookAsset->update(['status' => 'lost']);

        return response()->json([
            'message' => 'Book marked as lost. Penalty applied.',
            'penalty' => $penalty,
            'book_title' => $bookAsset->bookTitle->title,
            'transaction' => $transaction->load(['user', 'bookAsset.bookTitle'])
        ]);
    }

    // NEW: Get all pending fines for a student
    public function getStudentFines($studentId)
    {
        $student = User::where('student_id', $studentId)->first();

        if (!$student) {
            return response()->json(['message' => 'Student not found'], 404);
        }

        $fines = Transaction::with(['bookAsset.bookTitle'])
            ->where('user_id', $student->id)
            ->where('payment_status', 'pending')
            ->where('penalty_amount', '>', 0)
            ->get();

        return response()->json($fines);
    }

    // 3. VIEW USER HISTORY
    public function history(Request $request)
    {
        // If Admin, show all. If Student, show only theirs.
        // Use closure-based eager loading to include soft-deleted assets and titles
        $query = Transaction::with([
            'user',
            'bookAsset' => function ($q) {
                $q->withTrashed()->with([
                    'bookTitle' => function ($q2) {
                        $q2->withTrashed();
                    }
                ]);
            }
        ]);

        if ($request->user()->role === 'student') {
            $query->where('user_id', $request->user()->id);
        }

        return $query->latest()->paginate(15);
    }
    public function index(Request $request)
    {
        // Return all transactions with Student and Book details.
        // Frontend handles filtering (tabs) and pagination client-side.
        $transactions = \App\Models\Transaction::with([
            'user',
            'bookAsset' => function ($q) {
                $q->withTrashed()->with([
                    'bookTitle' => function ($q2) {
                        $q2->withTrashed();
                    }
                ]);
            }
        ])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($transactions);
    }

    /**
     * Mark a fine as paid
     * 
     * @param int $id - Transaction ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function markAsPaid($id)
    {
        $transaction = Transaction::find($id);

        if (!$transaction) {
            return response()->json(['message' => 'Transaction not found'], 404);
        }

        if ($transaction->penalty_amount <= 0) {
            return response()->json(['message' => 'No penalty to pay'], 400);
        }

        if ($transaction->payment_status === 'paid') {
            return response()->json(['message' => 'Already paid'], 400);
        }

        $transaction->update([
            'payment_status' => 'paid',
            'payment_date' => now()
        ]);

        return response()->json([
            'message' => 'Payment recorded successfully',
            'transaction' => $transaction->load(['user', 'bookAsset.bookTitle'])
        ]);
    }

    /**
     * Waive a fine (Admin only)
     * 
     * @param int $id - Transaction ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function waiveFine(Request $request, $id)
    {
        $request->validate([
            'reason' => 'required|string|max:255'
        ]);

        $transaction = Transaction::find($id);

        if (!$transaction) {
            return response()->json(['message' => 'Transaction not found'], 404);
        }

        $transaction->update([
            'payment_status' => 'waived',
            'payment_date' => now(),
            'remarks' => $request->reason
        ]);

        return response()->json([
            'message' => 'Fine waived successfully',
            'transaction' => $transaction->load(['user', 'bookAsset.bookTitle'])
        ]);
    }

    /**
     * Revert fine to Unpaid
     */
    public function markAsUnpaid($id)
    {
        $transaction = Transaction::find($id);

        if (!$transaction) {
            return response()->json(['message' => 'Transaction not found'], 404);
        }

        $transaction->update([
            'payment_status' => 'pending',
            'payment_date' => null
        ]);

        return response()->json([
            'message' => 'Fine marked as unpaid',
            'transaction' => $transaction->load(['user', 'bookAsset.bookTitle'])
        ]);
    }
    /**
     * Permanently delete a transaction and its associated orphaned book records.
     * Wrapped in DB::transaction() for atomicity.
     */
    public function forceDelete($id)
    {
        $transaction = Transaction::find($id);

        if (!$transaction) {
            return response()->json(['message' => 'Transaction not found'], 404);
        }

        DB::transaction(function () use ($transaction) {
            $bookAssetId = $transaction->book_asset_id;
            $bookAsset = \App\Models\BookAsset::withTrashed()->find($bookAssetId);
            $bookTitleId = $bookAsset ? $bookAsset->book_title_id : null;

            // 1. Delete the transaction first (removes FK reference)
            $transaction->delete();

            // 2. Only force-delete the BookAsset if NO other transactions reference it
            if ($bookAsset) {
                $remainingTxCount = Transaction::where('book_asset_id', $bookAssetId)->count();
                if ($remainingTxCount === 0) {
                    $bookAsset->forceDelete();
                }
            }

            // 3. Only force-delete the BookTitle if it's soft-deleted AND has no remaining assets
            if ($bookTitleId) {
                $bookTitle = \App\Models\BookTitle::withTrashed()->find($bookTitleId);
                if ($bookTitle && $bookTitle->trashed()) {
                    $remainingAssetsCount = \App\Models\BookAsset::withTrashed()
                        ->where('book_title_id', $bookTitleId)
                        ->count();
                    if ($remainingAssetsCount === 0) {
                        $bookTitle->forceDelete();
                    }
                }
            }
        });

        return response()->json(['message' => 'Record permanently deleted']);
    }

    /**
     * Bulk permanently delete transactions.
     *
     * Strategy: Delete all transactions FIRST to remove FK references,
     * then clean up orphaned BookAssets and BookTitles.
     * Wrapped in DB::transaction() for atomicity.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function forceDeleteBulk(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:transactions,id'
        ]);

        $ids = $request->ids;

        $count = DB::transaction(function () use ($ids) {
            // 1. Collect all affected asset IDs and title IDs BEFORE deletion
            $transactions = Transaction::whereIn('id', $ids)->get();
            $affectedAssetIds = $transactions->pluck('book_asset_id')->unique()->filter()->values()->toArray();

            $affectedTitleIds = \App\Models\BookAsset::withTrashed()
                ->whereIn('id', $affectedAssetIds)
                ->pluck('book_title_id')
                ->unique()
                ->filter()
                ->values()
                ->toArray();

            // 2. Bulk-delete all selected transactions (hard delete — no SoftDeletes on Transaction)
            $deletedCount = Transaction::whereIn('id', $ids)->delete();

            // 3. Clean up orphaned BookAssets (no remaining transactions reference them)
            foreach ($affectedAssetIds as $assetId) {
                $remainingTxCount = Transaction::where('book_asset_id', $assetId)->count();
                if ($remainingTxCount === 0) {
                    \App\Models\BookAsset::withTrashed()->where('id', $assetId)->forceDelete();
                }
            }

            // 4. Clean up orphaned BookTitles (soft-deleted with no remaining assets)
            foreach ($affectedTitleIds as $titleId) {
                $bookTitle = \App\Models\BookTitle::withTrashed()->find($titleId);
                if ($bookTitle && $bookTitle->trashed()) {
                    $remainingAssetsCount = \App\Models\BookAsset::withTrashed()
                        ->where('book_title_id', $titleId)
                        ->count();
                    if ($remainingAssetsCount === 0) {
                        $bookTitle->forceDelete();
                    }
                }
            }

            return $deletedCount;
        });

        return response()->json(['message' => "{$count} records permanently deleted"]);
    }
}
