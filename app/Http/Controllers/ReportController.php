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
                'book_titles.publisher',
                'book_titles.image_path',
                DB::raw('COUNT(transactions.id) as borrow_count')
            )
            ->groupBy('book_titles.id', 'book_titles.title', 'book_titles.author', 'book_titles.category', 'book_titles.publisher', 'book_titles.image_path');

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
                'users.course',
                'users.year_level',
                'users.section',
                'users.profile_picture',
                DB::raw('COUNT(transactions.id) as borrow_count'),
                DB::raw('SUM(CASE WHEN transactions.returned_at IS NULL THEN 1 ELSE 0 END) as active_loans')
            )
            ->where('users.role', 'student')
            ->groupBy('users.id', 'users.name', 'users.student_id', 'users.course', 'users.year_level', 'users.section', 'users.profile_picture');

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
     * Get Demographic Analytics
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function demographics(Request $request)
    {
        // 1. Borrowers by Course
        $byCourse = Transaction::query()
            ->join('users', 'transactions.user_id', '=', 'users.id')
            ->select('users.course', DB::raw('count(*) as total'))
            ->whereNotNull('users.course')
            ->where('users.role', 'student') // Ensure only students
            ->groupBy('users.course')
            ->orderByDesc('total')
            ->get();

        // 2. Borrowers by Year Level
        $byYear = Transaction::query()
            ->join('users', 'transactions.user_id', '=', 'users.id')
            ->select('users.year_level', DB::raw('count(*) as total'))
            ->whereNotNull('users.year_level')
            ->where('users.role', 'student')
            ->groupBy('users.year_level')
            ->orderBy('users.year_level')
            ->get();

        return response()->json([
            'by_course' => $byCourse,
            'by_year' => $byYear
        ]);
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
                    fputcsv($file, ['Rank', 'Student Name', 'Student ID', 'Course', 'Year', 'Section', 'Books Borrowed', 'Active Loans']);
                    $data = $this->getTopStudentsData($request);
                    foreach ($data as $index => $row) {
                        fputcsv($file, [
                            $index + 1,
                            $row->name,
                            $row->student_id,
                            $row->course,
                            $row->year_level,
                            $row->section,
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
                'users.course',
                'users.year_level',
                'users.section',
                DB::raw('COUNT(transactions.id) as borrow_count'),
                DB::raw('SUM(CASE WHEN transactions.returned_at IS NULL THEN 1 ELSE 0 END) as active_loans')
            )
            ->where('users.role', 'student')
            ->groupBy('users.id', 'users.name', 'users.student_id', 'users.course', 'users.year_level', 'users.section');

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

    /**
     * Get Borrowed Books Statistics by Call Number Range and Month
     * Uses the monthly_statistics table that is auto-updated on borrow
     */
    public function statistics(Request $request)
    {
        try {
            // Default to current academic year
            // If current month is Jan-May (e.g. Feb 2026), AY started in 2025
            // If current month is June-Dec (e.g. Sept 2025), AY starts in 2025
            $currentYear = (int) date('Y');
            $currentMonth = (int) date('n');
            $defaultYear = ($currentMonth < 6) ? $currentYear - 1 : $currentYear;
            
            $year = (int) $request->input('year', $defaultYear);
            
            // Ranges: 000-099, 100-199, ... 900-999
            $ranges = [];
            for ($i = 0; $i < 10; $i++) {
                $start = $i * 100;
                $end = $start + 99;
                $ranges[] = sprintf("%03d-%03d", $start, $end);
            }

            // Months order: June(6) to Dec(12), Jan(1) to May(5)
            $months = [6, 7, 8, 9, 10, 11, 12, 1, 2, 3, 4, 5];
            
            // Initialize matrix
            $matrix = [];
            foreach ($ranges as $range) {
                foreach ($months as $m) {
                    $matrix[$range][$m] = 0;
                }
            }

            // Fetch from monthly_statistics table
            // Academic year spans: June of $year to May of $year+1
            $stats = \App\Models\MonthlyStatistic::where(function ($query) use ($year) {
                // June to December of the start year
                $query->where(function ($q) use ($year) {
                    $q->where('year', $year)
                      ->whereIn('month', [6, 7, 8, 9, 10, 11, 12]);
                })
                // January to May of the next year
                ->orWhere(function ($q) use ($year) {
                    $q->where('year', $year + 1)
                      ->whereIn('month', [1, 2, 3, 4, 5]);
                });
            })->get();

            // Populate matrix with data
            foreach ($stats as $stat) {
                $rangeKey = sprintf("%03d-%03d", $stat->range_start, $stat->range_end);
                if (isset($matrix[$rangeKey][$stat->month])) {
                    $matrix[$rangeKey][$stat->month] = $stat->count;
                }
            }

            return response()->json([
                'year' => $year,
                'academic_year' => "A.Y. $year-" . ($year + 1),
                'ranges' => $ranges,
                'months' => $months,
                'data' => $matrix
            ]);
        } catch (\Exception $e) {
            \Log::error('Statistics endpoint error: ' . $e->getMessage());
            return response()->json([
                'error' => $e->getMessage(),
                'year' => $request->input('year'),
                'ranges' => [],
                'months' => [],
                'data' => []
            ], 500);
        }
    }
}
