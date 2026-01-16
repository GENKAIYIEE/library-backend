<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Transaction;
use App\Models\BookAsset;
use App\Models\BookTitle;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class StudentController extends Controller
{
    /**
     * Generate the next sequential student ID.
     * Format: YYYY-XXXX (e.g., 2026-0001, 2026-0002)
     */
    private function generateStudentId(): string
    {
        $year = date('Y');
        $prefix = $year . '-';

        // Find the latest student_id for the current year
        $latestStudent = User::where('student_id', 'like', $prefix . '%')
            ->orderBy('student_id', 'desc')
            ->first();

        if ($latestStudent) {
            // Extract the sequence number and increment
            $lastSequence = (int) substr($latestStudent->student_id, strlen($prefix));
            $nextSequence = $lastSequence + 1;
        } else {
            $nextSequence = 1;
        }

        // Format with leading zeros (4 digits)
        return $prefix . str_pad($nextSequence, 4, '0', STR_PAD_LEFT);
    }

    // 1. GET ALL STUDENTS
    public function index()
    {
        // Get only users who are 'student'
        return User::where('role', 'student')->orderBy('created_at', 'desc')->get();
    }

    // 2. REGISTER A NEW STUDENT
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'course' => 'required|string',
            'year_level' => 'required|integer',
            'section' => 'required|string',
            'email' => 'nullable|email'
        ]);

        // Auto-generate the student ID
        $studentId = $this->generateStudentId();

        $user = User::create([
            'name' => $request->name,
            'student_id' => $studentId,
            'course' => $request->course,
            'year_level' => $request->year_level,
            'section' => $request->section,
            'email' => $request->email ?? $studentId . '@pclu.edu',
            'password' => Hash::make('password'),
            'role' => 'student'
        ]);

        return response()->json($user);
    }

    // 3. DELETE STUDENT
    public function destroy($id)
    {
        $user = User::find($id);
        if ($user) {
            $user->delete();
            return response()->json(['message' => 'Student deleted']);
        }
        return response()->json(['message' => 'Not found'], 404);
    }

    // 4. BATCH REGISTER STUDENTS
    public function batchStore(Request $request)
    {
        $request->validate([
            'course' => 'required|string',
            'year_level' => 'required|integer',
            'section' => 'required|string',
            'students' => 'required|array|min:1',
            'students.*.name' => 'required|string'
        ]);

        $created = [];
        $errors = [];

        DB::beginTransaction();

        try {
            foreach ($request->students as $index => $studentData) {
                // Auto-generate unique student ID for each student
                $studentId = $this->generateStudentId();

                $user = User::create([
                    'name' => $studentData['name'],
                    'student_id' => $studentId,
                    'course' => $request->course,
                    'year_level' => $request->year_level,
                    'section' => $request->section,
                    'email' => $studentId . '@pclu.edu',
                    'password' => Hash::make('password'),
                    'role' => 'student'
                ]);

                $created[] = $user;
            }

            DB::commit();

            return response()->json([
                'message' => count($created) . ' student(s) registered successfully.',
                'registered' => count($created),
                'errors' => $errors
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Batch registration failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * 5. GET TOP READERS LEADERBOARD
     * Returns top 10 students ranked by total books borrowed (returned)
     */
    public function leaderboard()
    {
        $leaderboard = User::where('role', 'student')
            ->withCount([
                'transactions as books_borrowed' => function ($query) {
                    $query->whereNotNull('returned_at');
                }
            ])
            ->withCount([
                'transactions as active_loans' => function ($query) {
                    $query->whereNull('returned_at');
                }
            ])
            ->having('books_borrowed', '>', 0)
            ->orderByDesc('books_borrowed')
            ->limit(10)
            ->get()
            ->map(function ($student, $index) {
                // Calculate badges count for each student
                $badges = $this->calculateBadges($student->id);
                return [
                    'rank' => $index + 1,
                    'id' => $student->id,
                    'name' => $student->name,
                    'student_id' => $student->student_id,
                    'course' => $student->course,
                    'books_borrowed' => $student->books_borrowed,
                    'active_loans' => $student->active_loans,
                    'badges_count' => count(array_filter($badges, fn($b) => $b['unlocked']))
                ];
            });

        return response()->json($leaderboard);
    }

    /**
     * 6. GET STUDENT ACHIEVEMENTS/BADGES
     * Returns all badges with their unlock status for a student
     */
    public function achievements($id)
    {
        $student = User::find($id);

        if (!$student || $student->role !== 'student') {
            return response()->json(['message' => 'Student not found'], 404);
        }

        $badges = $this->calculateBadges($id);

        return response()->json([
            'student' => [
                'id' => $student->id,
                'name' => $student->name,
                'student_id' => $student->student_id,
                'course' => $student->course
            ],
            'badges' => $badges,
            'unlocked_count' => count(array_filter($badges, fn($b) => $b['unlocked'])),
            'total_count' => count($badges)
        ]);
    }

    /**
     * Calculate badges for a student based on their borrowing history
     */
    private function calculateBadges($studentId)
    {
        // Get total books borrowed (returned)
        $totalBorrowed = Transaction::where('user_id', $studentId)
            ->whereNotNull('returned_at')
            ->count();

        // Get on-time returns (no penalty)
        $onTimeReturns = Transaction::where('user_id', $studentId)
            ->whereNotNull('returned_at')
            ->where(function ($q) {
                $q->where('penalty_amount', 0)->orWhereNull('penalty_amount');
            })
            ->count();

        // Get total transactions
        $totalTransactions = Transaction::where('user_id', $studentId)
            ->whereNotNull('returned_at')
            ->count();

        // Get books by category
        $categoryStats = Transaction::where('user_id', $studentId)
            ->whereNotNull('returned_at')
            ->join('book_assets', 'transactions.book_asset_id', '=', 'book_assets.id')
            ->join('book_titles', 'book_assets.book_title_id', '=', 'book_titles.id')
            ->select('book_titles.category', DB::raw('count(*) as count'))
            ->groupBy('book_titles.category')
            ->pluck('count', 'category')
            ->toArray();

        // Define all badges
        $badges = [
            [
                'id' => 'first_read',
                'name' => 'First Read',
                'description' => 'Borrowed your first book',
                'icon' => 'BookOpen',
                'color' => 'blue',
                'criteria' => '1+ book borrowed',
                'unlocked' => $totalBorrowed >= 1,
                'progress' => min($totalBorrowed, 1),
                'target' => 1
            ],
            [
                'id' => 'bookworm',
                'name' => 'Bookworm',
                'description' => 'Borrowed 5 books',
                'icon' => 'Star',
                'color' => 'yellow',
                'criteria' => '5+ books borrowed',
                'unlocked' => $totalBorrowed >= 5,
                'progress' => min($totalBorrowed, 5),
                'target' => 5
            ],
            [
                'id' => 'super_reader',
                'name' => 'Super Reader',
                'description' => 'Borrowed 10 books',
                'icon' => 'Trophy',
                'color' => 'gold',
                'criteria' => '10+ books borrowed',
                'unlocked' => $totalBorrowed >= 10,
                'progress' => min($totalBorrowed, 10),
                'target' => 10
            ],
            [
                'id' => 'library_legend',
                'name' => 'Library Legend',
                'description' => 'Borrowed 25 books',
                'icon' => 'Crown',
                'color' => 'purple',
                'criteria' => '25+ books borrowed',
                'unlocked' => $totalBorrowed >= 25,
                'progress' => min($totalBorrowed, 25),
                'target' => 25
            ],
            [
                'id' => 'scifi_explorer',
                'name' => 'Sci-Fi Explorer',
                'description' => 'Read 3 Science books',
                'icon' => 'Atom',
                'color' => 'cyan',
                'criteria' => '3+ Science books',
                'unlocked' => ($categoryStats['Science'] ?? 0) >= 3,
                'progress' => min($categoryStats['Science'] ?? 0, 3),
                'target' => 3
            ],
            [
                'id' => 'fiction_fan',
                'name' => 'Fiction Fan',
                'description' => 'Read 3 Fiction books',
                'icon' => 'BookMarked',
                'color' => 'pink',
                'criteria' => '3+ Fiction books',
                'unlocked' => ($categoryStats['Fiction'] ?? 0) >= 3,
                'progress' => min($categoryStats['Fiction'] ?? 0, 3),
                'target' => 3
            ],
            [
                'id' => 'tech_enthusiast',
                'name' => 'Tech Enthusiast',
                'description' => 'Read 3 Technology books',
                'icon' => 'Laptop',
                'color' => 'green',
                'criteria' => '3+ Technology books',
                'unlocked' => ($categoryStats['Technology'] ?? 0) >= 3,
                'progress' => min($categoryStats['Technology'] ?? 0, 3),
                'target' => 3
            ],
            [
                'id' => 'history_buff',
                'name' => 'History Buff',
                'description' => 'Read 3 History books',
                'icon' => 'Scroll',
                'color' => 'amber',
                'criteria' => '3+ History books',
                'unlocked' => ($categoryStats['History'] ?? 0) >= 3,
                'progress' => min($categoryStats['History'] ?? 0, 3),
                'target' => 3
            ],
            [
                'id' => 'early_bird',
                'name' => 'Early Bird',
                'description' => 'All books returned on time',
                'icon' => 'Clock',
                'color' => 'emerald',
                'criteria' => '5+ on-time returns, 100% rate',
                'unlocked' => $totalTransactions >= 5 && $onTimeReturns === $totalTransactions,
                'progress' => $onTimeReturns,
                'target' => max($totalTransactions, 5)
            ],
            [
                'id' => 'diverse_reader',
                'name' => 'Diverse Reader',
                'description' => 'Read from 3+ categories',
                'icon' => 'Layers',
                'color' => 'indigo',
                'criteria' => '3+ different categories',
                'unlocked' => count($categoryStats) >= 3,
                'progress' => count($categoryStats),
                'target' => 3
            ]
        ];

        return $badges;
    }
}