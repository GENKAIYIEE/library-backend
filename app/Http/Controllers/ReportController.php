<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Transaction;
use App\Models\BookTitle;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    /**
     * Get Most Borrowed Books Report
     * 
     * @param Request $request - Optional: start_date, end_date
     * @return \Illuminate\Http\JsonResponse
     */
    public function mostBorrowed(Request $request)
    {
        $query = Transaction::query()
            ->join('book_assets', 'transactions.book_asset_id', '=', 'book_assets.id')
            ->join('book_titles', 'book_assets.book_title_id', '=', 'book_titles.id')
            ->whereNull('book_assets.deleted_at')
            ->whereNull('book_titles.deleted_at')
            ->select(
                'book_titles.id',
                'book_titles.title',
                'book_titles.author',
                'book_titles.category',
                DB::raw('COUNT(transactions.id) as borrow_count')
            )
            ->groupBy('book_titles.id', 'book_titles.title', 'book_titles.author', 'book_titles.category');

        // Apply date filters
        if ($request->has('start_date')) {
            $query->where('transactions.borrowed_at', '>=', $request->start_date);
        }
        if ($request->has('end_date')) {
            $query->where('transactions.borrowed_at', '<=', $request->end_date);
        }

        $results = $query->orderByDesc('borrow_count')->limit(10)->get();

        return response()->json($results);
    }

    /**
     * Get Top Students (Borrowers) Report
     * 
     * @param Request $request - Optional: start_date, end_date
     * @return \Illuminate\Http\JsonResponse
     */
    public function topStudents(Request $request)
    {
        $query = Transaction::query()
            ->join('users', 'transactions.user_id', '=', 'users.id')
            ->whereNull('users.deleted_at')
            ->select(
                'users.id',
                'users.name',
                'users.student_id',
                DB::raw('COUNT(transactions.id) as borrow_count'),
                DB::raw('SUM(CASE WHEN transactions.returned_at IS NULL THEN 1 ELSE 0 END) as active_loans')
            )
            ->where('users.role', 'student')
            ->groupBy('users.id', 'users.name', 'users.student_id');

        // Apply date filters
        if ($request->has('start_date')) {
            $query->where('transactions.borrowed_at', '>=', $request->start_date);
        }
        if ($request->has('end_date')) {
            $query->where('transactions.borrowed_at', '<=', $request->end_date);
        }

        $results = $query->orderByDesc('borrow_count')->limit(10)->get();

        return response()->json($results);
    }

    /**
     * Get Monthly Penalty Collection Report
     * 
     * @param Request $request - Optional: start_date, end_date
     * @return \Illuminate\Http\JsonResponse
     */
    public function penalties(Request $request)
    {
        $query = Transaction::query()
            ->select(
                DB::raw('DATE_FORMAT(returned_at, "%Y-%m") as month'),
                DB::raw('SUM(penalty_amount) as total_penalties'),
                DB::raw('SUM(CASE WHEN payment_status = "paid" THEN penalty_amount ELSE 0 END) as collected'),
                DB::raw('SUM(CASE WHEN payment_status = "pending" THEN penalty_amount ELSE 0 END) as pending'),
                DB::raw('COUNT(CASE WHEN penalty_amount > 0 THEN 1 END) as late_returns')
            )
            ->whereNotNull('returned_at')
            ->groupBy(DB::raw('DATE_FORMAT(returned_at, "%Y-%m")'));

        // Apply date filters
        if ($request->has('start_date')) {
            $query->where('returned_at', '>=', $request->start_date);
        }
        if ($request->has('end_date')) {
            $query->where('returned_at', '<=', $request->end_date);
        }

        $results = $query->orderByDesc('month')->get();

        // Calculate summary
        $summary = [
            'total_fines' => $results->sum('total_penalties'),
            'total_collected' => $results->sum('collected'),
            'total_pending' => $results->sum('pending'),
            'total_late_returns' => $results->sum('late_returns')
        ];

        return response()->json([
            'monthly' => $results,
            'summary' => $summary
        ]);
    }

    /**
     * Export Report as CSV
     * 
     * @param Request $request
     * @param string $type - 'books', 'students', 'penalties'
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function exportCsv(Request $request, $type)
    {
        $filename = "report_{$type}_" . date('Y-m-d') . ".csv";

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ];

        $callback = function () use ($request, $type) {
            $file = fopen('php://output', 'w');

            switch ($type) {
                case 'books':
                    fputcsv($file, ['Rank', 'Title', 'Author', 'Category', 'Times Borrowed']);
                    $data = $this->getMostBorrowedData($request);
                    foreach ($data as $index => $row) {
                        fputcsv($file, [
                            $index + 1,
                            $row->title,
                            $row->author,
                            $row->category,
                            $row->borrow_count
                        ]);
                    }
                    break;

                case 'students':
                    fputcsv($file, ['Rank', 'Student Name', 'Student ID', 'Books Borrowed', 'Active Loans']);
                    $data = $this->getTopStudentsData($request);
                    foreach ($data as $index => $row) {
                        fputcsv($file, [
                            $index + 1,
                            $row->name,
                            $row->student_id,
                            $row->borrow_count,
                            $row->active_loans
                        ]);
                    }
                    break;

                case 'penalties':
                    fputcsv($file, ['Month', 'Total Fines', 'Collected', 'Pending', 'Late Returns']);
                    $data = $this->getPenaltiesData($request);
                    foreach ($data as $row) {
                        fputcsv($file, [
                            $row->month,
                            '₱' . number_format($row->total_penalties, 2),
                            '₱' . number_format($row->collected, 2),
                            '₱' . number_format($row->pending, 2),
                            $row->late_returns
                        ]);
                    }
                    break;
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    // Helper methods for export
    private function getMostBorrowedData($request)
    {
        $query = Transaction::query()
            ->join('book_assets', 'transactions.book_asset_id', '=', 'book_assets.id')
            ->join('book_titles', 'book_assets.book_title_id', '=', 'book_titles.id')
            ->whereNull('book_assets.deleted_at')
            ->whereNull('book_titles.deleted_at')
            ->select(
                'book_titles.title',
                'book_titles.author',
                'book_titles.category',
                DB::raw('COUNT(transactions.id) as borrow_count')
            )
            ->groupBy('book_titles.id', 'book_titles.title', 'book_titles.author', 'book_titles.category');

        if ($request->has('start_date')) {
            $query->where('transactions.borrowed_at', '>=', $request->start_date);
        }
        if ($request->has('end_date')) {
            $query->where('transactions.borrowed_at', '<=', $request->end_date);
        }

        return $query->orderByDesc('borrow_count')->limit(50)->get();
    }

    private function getTopStudentsData($request)
    {
        $query = Transaction::query()
            ->join('users', 'transactions.user_id', '=', 'users.id')
            ->whereNull('users.deleted_at')
            ->select(
                'users.name',
                'users.student_id',
                DB::raw('COUNT(transactions.id) as borrow_count'),
                DB::raw('SUM(CASE WHEN transactions.returned_at IS NULL THEN 1 ELSE 0 END) as active_loans')
            )
            ->where('users.role', 'student')
            ->groupBy('users.id', 'users.name', 'users.student_id');

        if ($request->has('start_date')) {
            $query->where('transactions.borrowed_at', '>=', $request->start_date);
        }
        if ($request->has('end_date')) {
            $query->where('transactions.borrowed_at', '<=', $request->end_date);
        }

        return $query->orderByDesc('borrow_count')->limit(50)->get();
    }

    private function getPenaltiesData($request)
    {
        $query = Transaction::query()
            ->select(
                DB::raw('DATE_FORMAT(returned_at, "%Y-%m") as month'),
                DB::raw('SUM(penalty_amount) as total_penalties'),
                DB::raw('SUM(CASE WHEN payment_status = "paid" THEN penalty_amount ELSE 0 END) as collected'),
                DB::raw('SUM(CASE WHEN payment_status = "pending" THEN penalty_amount ELSE 0 END) as pending'),
                DB::raw('COUNT(CASE WHEN penalty_amount > 0 THEN 1 END) as late_returns')
            )
            ->whereNotNull('returned_at')
            ->groupBy(DB::raw('DATE_FORMAT(returned_at, "%Y-%m")'));

        if ($request->has('start_date')) {
            $query->where('returned_at', '>=', $request->start_date);
        }
        if ($request->has('end_date')) {
            $query->where('returned_at', '<=', $request->end_date);
        }

        return $query->orderByDesc('month')->get();
    }
    /**
     * Get Departmental Analytics Stats
     * 
     * @param Request $request - course (required), year_level, section
     * @return \Illuminate\Http\JsonResponse
     */
    public function departmentStats(Request $request)
    {
        $course = $request->input('course');
        $year = $request->input('year_level');
        $section = $request->input('section');

        // Base query for users in this department
        $usersQuery = User::where('role', 'student');

        if ($course)
            $usersQuery->where('course', $course);
        if ($year)
            $usersQuery->where('year_level', $year);
        if ($section)
            $usersQuery->where('section', $section);

        $students = $usersQuery->get();
        $studentIds = $students->pluck('id');

        // Stats
        $totalStudents = $students->count();

        // Active Borrowers (unique students with unreturned books)
        $activeBorrowers = Transaction::whereIn('user_id', $studentIds)
            ->whereNull('returned_at')
            ->distinct('user_id')
            ->count('user_id');

        // Late Returners (unique students with overdue books)
        $lateReturners = Transaction::whereIn('user_id', $studentIds)
            ->whereNull('returned_at')
            ->where('due_date', '<', now())
            ->distinct('user_id')
            ->count('user_id');

        // Total Pending Fines
        $pendingFines = Transaction::whereIn('user_id', $studentIds)
            ->where('payment_status', 'pending')
            ->sum('penalty_amount');

        // Student Breakdown
        $breakdown = $students->map(function ($student) {
            $activeLoans = Transaction::where('user_id', $student->id)
                ->whereNull('returned_at')
                ->count();

            $hasOverdue = Transaction::where('user_id', $student->id)
                ->whereNull('returned_at')
                ->where('due_date', '<', now())
                ->exists();

            $totalFine = Transaction::where('user_id', $student->id)
                ->where('payment_status', 'pending')
                ->sum('penalty_amount');

            return [
                'id' => $student->id,
                'name' => $student->name,
                'student_id' => $student->student_id,
                'year_level' => $student->year_level,
                'section' => $student->section,
                'active_loans' => $activeLoans,
                'status' => $hasOverdue ? 'Overdue' : ($activeLoans > 0 ? 'Active' : 'Clear'),
                'pending_fine' => $totalFine
            ];
        });

        return response()->json([
            'stats' => [
                'total_students' => $totalStudents,
                'active_borrowers' => $activeBorrowers,
                'late_returners' => $lateReturners,
                'pending_fines' => $pendingFines
            ],
            'students' => $breakdown
        ]);
    }
}
