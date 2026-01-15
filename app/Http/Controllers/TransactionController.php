<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Transaction;
use App\Models\BookAsset;
use App\Models\User;
use Carbon\Carbon;

class TransactionController extends Controller
{
    // 1. BORROW A BOOK (Scan to Borrow)
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

        // 3. Check if Book is available
        if ($bookAsset->status !== 'available') {
            return response()->json(['message' => 'Book is already borrowed!'], 400);
        }

        // 4. Create Transaction
        $transaction = Transaction::create([
            'user_id' => $student->id,
            'book_asset_id' => $bookAsset->id,
            'borrowed_at' => now(),
            'due_date' => now()->addDays(7), // Due in 7 days
            'processed_by' => $request->user()->id // <--- THIS FIXES THE ERROR
        ]);

        // 5. Update Book Status
        $bookAsset->update(['status' => 'borrowed']);

        return response()->json(['message' => 'Success', 'data' => $transaction]);
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

        // 3. Check for Late Return
        $today = now();
        $dueDate = \Carbon\Carbon::parse($transaction->due_date);

        $penalty = 0;
        $daysLate = 0;

        // If Today is AFTER the Due Date
        if ($today->gt($dueDate)) {
            $daysLate = $today->diffInDays($dueDate);
            $finePerDay = 5.00; // <--- RULE: 5 PESOS PER DAY
            $penalty = $daysLate * $finePerDay;
        }

        // 4. Update the Record
        $transaction->update([
            'returned_at' => now(),
            'penalty_amount' => $penalty,
            'payment_status' => $penalty > 0 ? 'pending' : 'paid'
        ]);

        // 5. Make the book available again
        $bookAsset->update(['status' => 'available']);

        // 6. Send the result back to the Frontend
        return response()->json([
            'message' => 'Book returned successfully',
            'days_late' => $daysLate,
            'penalty' => $penalty
        ]);
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
    public function waiveFine($id)
    {
        $transaction = Transaction::find($id);

        if (!$transaction) {
            return response()->json(['message' => 'Transaction not found'], 404);
        }

        $transaction->update([
            'payment_status' => 'waived',
            'payment_date' => now()
        ]);

        return response()->json([
            'message' => 'Fine waived successfully',
            'transaction' => $transaction->load(['user', 'bookAsset.bookTitle'])
        ]);
    }
}