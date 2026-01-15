<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

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

        \DB::beginTransaction();

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

            \DB::commit();

            return response()->json([
                'message' => count($created) . ' student(s) registered successfully.',
                'registered' => count($created),
                'errors' => $errors
            ]);

        } catch (\Exception $e) {
            \DB::rollBack();
            return response()->json(['message' => 'Batch registration failed: ' . $e->getMessage()], 500);
        }
    }
}