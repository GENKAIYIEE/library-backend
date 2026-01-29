<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Transaction;
use App\Models\BookAsset;
use App\Models\User;
use Carbon\Carbon;

class TransactionController extends Controller
{
    // Course-based loan periods (in days)
    private function getLoanDays($course)
    {
        $periods = [
            'Maritime' => 1,
            'BSIT' => 7,
            'BSED' => 7,
            'BEED' => 7,
            'BSHM' => 7,
            'BS Criminology' => 7,
            'BSBA' => 7,
            'BS Tourism' => 7
        ];

        return $periods[$course] ?? 7; // Default 7 days
    }

    // 1. BORROW A BOOK (With Course-Specific Rules)
    public function borrow(Request $request)
    {
        // 1. Validate
        $request->validate([
            'student_id' => 'required|exists:users,student_id',
            'asset_code' => 'required|exists:book_assets,asset_code'
        ]);

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

        // 4. Check if student already has too many books (optional limit)
        $activeLoans = Transaction::where('user_id', $student->id)
            ->whereNull('returned_at')
            ->count();

        if ($activeLoans >= 3) {
            return response()->json([
                'message' => 'Student has reached the maximum limit of 3 active loans.',
                'blocked' => true
            ], 403);
        }

        // 5. Check if Book is available
        if ($bookAsset->status !== 'available') {
            return response()->json(['message' => 'Book is already borrowed!'], 400);
        }

        // 6. Calculate due date based on course
        $loanDays = $this->getLoanDays($student->course);
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

        return response()->json([
            'message' => 'Success! Book borrowed.',
            'data' => $transaction,
            'loan_days' => $loanDays,
            'due_date' => $dueDate->format('Y-m-d'),
            'course' => $student->course
        ]);
    }

    public function returnBook(Request $request)
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

            $finePerDay = 5.00; // <--- RULE: 5 PESOS PER DAY
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
            'transaction' => $transaction->load('user')
        ]);
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
        $query = Transaction::with(['user', 'bookAsset.bookTitle']);

        if ($request->user()->role === 'student') {
            $query->where('user_id', $request->user()->id);
        }

        return $query->latest()->get();
    }
    public function index()
    {
        // Get all transactions with Student and Book details
        $transactions = \App\Models\Transaction::with(['user', 'bookAsset.bookTitle'])
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
}