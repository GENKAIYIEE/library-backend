<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\FacultyTransaction;
use App\Models\Faculty;
use App\Models\BookAsset;
use App\Models\LibrarySetting;
use App\Models\MonthlyStatistic;
use Carbon\Carbon;

class FacultyTransactionController extends Controller
{
    /**
     * Get loan days from settings (same for all borrowers)
     */
    private function getLoanDays()
    {
        return LibrarySetting::getDefaultLoanDays();
    }

    /**
     * Get max loans per faculty from settings
     */
    private function getMaxLoansPerFaculty()
    {
        // Faculty can have slightly higher limit (5 vs 3 for students)
        return (int) LibrarySetting::getValue('max_loans_per_faculty', 5);
    }

    /**
     * Get fine per day from settings
     */
    private function getFinePerDay()
    {
        return LibrarySetting::getFinePerDay();
    }

    /**
     * 1. FACULTY BORROW A BOOK
     */
    public function borrow(Request $request)
    {
        $request->validate([
            'faculty_id' => 'required|exists:faculties,id',
            'asset_code' => 'required|string',
        ]);

        // Find faculty
        $faculty = Faculty::findOrFail($request->faculty_id);

        if ($faculty->status !== 'active') {
            return response()->json([
                'message' => 'This faculty member is inactive and cannot borrow books.'
            ], 422);
        }

        // Find the book asset
        $bookAsset = BookAsset::where('asset_code', $request->asset_code)
            ->whereNull('deleted_at')
            ->first();

        if (!$bookAsset) {
            return response()->json([
                'message' => 'Book not found. Please check the barcode/asset code.'
            ], 404);
        }

        // Check if book is already borrowed
        $existingLoan = FacultyTransaction::where('book_asset_id', $bookAsset->id)
            ->whereNull('returned_at')
            ->first();

        if ($existingLoan) {
            return response()->json([
                'message' => 'This book is already borrowed by a faculty member.'
            ], 422);
        }

        // Also check student transactions
        $studentLoan = \App\Models\Transaction::where('book_asset_id', $bookAsset->id)
            ->whereNull('returned_at')
            ->first();

        if ($studentLoan) {
            return response()->json([
                'message' => 'This book is currently borrowed by a student.'
            ], 422);
        }

        // Check if book is damaged
        if ($bookAsset->is_damaged) {
            return response()->json([
                'message' => 'This book is marked as damaged and cannot be borrowed.'
            ], 422);
        }

        // Check max loans
        $activeLoans = $faculty->activeLoans()->count();
        $maxLoans = $this->getMaxLoansPerFaculty();

        if ($activeLoans >= $maxLoans) {
            return response()->json([
                'message' => "Faculty has reached the maximum loan limit of {$maxLoans} books."
            ], 422);
        }

        // Check for unpaid fines
        $pendingFines = $faculty->pending_fines;
        if ($pendingFines > 0) {
            return response()->json([
                'message' => "Faculty has ₱" . number_format($pendingFines, 2) . " in unpaid fines. Please settle before borrowing."
            ], 422);
        }

        // Calculate due date
        $loanDays = $this->getLoanDays();
        $dueDate = Carbon::now()->addDays($loanDays)->format('Y-m-d');

        // Create transaction
        $transaction = FacultyTransaction::create([
            'faculty_id' => $faculty->id,
            'book_asset_id' => $bookAsset->id,
            'borrowed_at' => now(),
            'due_date' => $dueDate,
            'processed_by' => auth()->id(),
        ]);

        // Update monthly statistics for faculty
        $this->updateMonthlyStatistic($bookAsset, 'faculty');

        // Load relationships for response
        $transaction->load(['bookAsset.bookTitle', 'faculty']);

        return response()->json([
            'message' => 'Book issued to faculty successfully!',
            'transaction' => $transaction,
            'due_date' => $dueDate,
            'faculty_name' => $faculty->name,
            'book_title' => $transaction->bookAsset->bookTitle->title ?? 'Unknown',
        ], 201);
    }

    /**
     * 2. FACULTY RETURN A BOOK
     */
    public function returnBook(Request $request)
    {
        $request->validate([
            'asset_code' => 'required|string',
        ]);

        // Find the book asset
        $bookAsset = BookAsset::where('asset_code', $request->asset_code)->first();

        if (!$bookAsset) {
            return response()->json([
                'message' => 'Book not found.'
            ], 404);
        }

        // Find active loan
        $transaction = FacultyTransaction::where('book_asset_id', $bookAsset->id)
            ->whereNull('returned_at')
            ->first();

        if (!$transaction) {
            return response()->json([
                'message' => 'This book is not currently borrowed by any faculty member.'
            ], 404);
        }

        // Calculate penalty if overdue
        $returnDate = now();
        $dueDate = Carbon::parse($transaction->due_date);
        $penalty = 0;
        $paymentStatus = null;

        if ($returnDate->gt($dueDate)) {
            $daysLate = $returnDate->diffInDays($dueDate);
            $finePerDay = $this->getFinePerDay();
            $penalty = $daysLate * $finePerDay;
            $paymentStatus = 'pending';
        }

        // Update transaction
        $transaction->update([
            'returned_at' => $returnDate,
            'penalty_amount' => $penalty,
            'payment_status' => $paymentStatus,
        ]);

        $transaction->load(['bookAsset.bookTitle', 'faculty']);

        return response()->json([
            'message' => 'Book returned successfully!',
            'transaction' => $transaction,
            'faculty_name' => $transaction->faculty->name ?? 'Unknown',
            'book_title' => $transaction->bookAsset->bookTitle->title ?? 'Unknown',
            'returned_at' => $returnDate,
            'days_late' => $penalty > 0 ? $returnDate->diffInDays($dueDate) : 0,
            'penalty' => $penalty,
        ]);
    }

    /**
     * 3. GET FACULTY BORROWED BOOKS (for return lookup)
     */
    public function getBorrowedBooks()
    {
        $borrowed = FacultyTransaction::whereNull('returned_at')
            ->with(['faculty', 'bookAsset.bookTitle'])
            ->orderBy('due_date')
            ->get()
            ->map(function ($tx) {
                $isOverdue = Carbon::parse($tx->due_date)->lt(now());
                return [
                    'id' => $tx->id,
                    'faculty_id' => $tx->faculty_id,
                    'faculty_name' => $tx->faculty->name ?? 'Unknown',
                    'faculty_code' => $tx->faculty->faculty_id ?? 'N/A',
                    'department' => $tx->faculty->department ?? 'N/A',
                    'book_title' => $tx->bookAsset->bookTitle->title ?? 'Unknown',
                    'asset_code' => $tx->bookAsset->asset_code ?? 'N/A',
                    'borrowed_at' => $tx->borrowed_at,
                    'due_date' => $tx->due_date,
                    'is_overdue' => $isOverdue,
                    'days_overdue' => $isOverdue ? now()->diffInDays($tx->due_date) : 0,
                ];
            });

        return response()->json($borrowed);
    }

    /**
     * 4. MARK FINE AS PAID
     */
    public function markAsPaid($id)
    {
        $transaction = FacultyTransaction::findOrFail($id);

        if ($transaction->payment_status !== 'pending') {
            return response()->json([
                'message' => 'This transaction has no pending fine.'
            ], 422);
        }

        $transaction->update([
            'payment_status' => 'paid',
            'payment_date' => now(),
        ]);

        return response()->json([
            'message' => 'Fine marked as paid!',
            'transaction' => $transaction,
        ]);
    }

    /**
     * 5. WAIVE FINE
     */
    public function waiveFine(Request $request, $id)
    {
        $transaction = FacultyTransaction::findOrFail($id);

        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        if ($transaction->payment_status !== 'pending') {
            return response()->json([
                'message' => 'This transaction has no pending fine to waive.'
            ], 422);
        }

        $transaction->update([
            'payment_status' => 'waived',
            'remarks' => $request->reason,
        ]);

        return response()->json([
            'message' => 'Fine waived successfully!',
            'transaction' => $transaction,
        ]);
    }

    /**
     * 6. GET FACULTY FINES
     */
    public function getFacultyFines($facultyId)
    {
        $fines = FacultyTransaction::where('faculty_id', $facultyId)
            ->where('payment_status', 'pending')
            ->with(['bookAsset.bookTitle'])
            ->get()
            ->map(function ($tx) {
                return [
                    'id' => $tx->id,
                    'book_title' => $tx->bookAsset->bookTitle->title ?? 'Unknown',
                    'asset_code' => $tx->bookAsset->asset_code ?? 'N/A',
                    'due_date' => $tx->due_date,
                    'returned_at' => $tx->returned_at,
                    'penalty_amount' => $tx->penalty_amount,
                ];
            });

        return response()->json([
            'fines' => $fines,
            'total' => $fines->sum('penalty_amount'),
        ]);
    }

    /**
     * 7. GET ALL TRANSACTIONS (for history)
     */
    public function index()
    {
        $transactions = FacultyTransaction::with(['faculty', 'bookAsset.bookTitle'])
            ->orderByDesc('borrowed_at')
            ->limit(100)
            ->get();

        return response()->json($transactions);
    }

    /**
     * Update monthly statistics (increment faculty borrow count)
     */
    private function updateMonthlyStatistic($bookAsset, $type = 'faculty')
    {
        $bookTitle = $bookAsset->bookTitle;
        if (!$bookTitle || !$bookTitle->call_number) {
            return;
        }

        // Extract the class number from call number
        $callNumber = $bookTitle->call_number;
        preg_match('/^(\d{3})/', $callNumber, $matches);

        if (empty($matches)) {
            return;
        }

        $classNumber = (int) $matches[1];
        $rangeStart = floor($classNumber / 100) * 100;
        $rangeEnd = $rangeStart + 99;

        $year = (int) date('Y');
        $month = (int) date('n');

        // For faculty, we'll track in a separate field or just use the same statistics
        // For now, we'll increment the same counter (can be separated later)
        MonthlyStatistic::updateOrCreate(
            [
                'year' => $year,
                'month' => $month,
                'range_start' => $rangeStart,
                'range_end' => $rangeEnd,
            ],
            []
        )->increment('count');
    }
}
