<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class StudentController extends Controller
{
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
            'student_id' => 'required|string|unique:users,student_id',
            'course' => 'required|string',
            'year_level' => 'required|integer',
            'section' => 'required|string',
            'email' => 'nullable|email'
        ]);

        $user = User::create([
            'name' => $request->name,
            'student_id' => $request->student_id,
            'course' => $request->course,
            'year_level' => $request->year_level,
            'section' => $request->section,
            'email' => $request->email ?? $request->student_id . '@pclu.edu', // Auto-generate email if empty
            'password' => Hash::make('password'), // Default password is "password"
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
            'students.*.name' => 'required|string',
            'students.*.student_id' => 'required|string|distinct'
        ]);

        $created = [];
        $errors = [];

        \DB::beginTransaction();

        try {
            foreach ($request->students as $index => $studentData) {
                // Check if student_id already exists
                if (User::where('student_id', $studentData['student_id'])->exists()) {
                    $errors[] = "Row " . ($index + 1) . ": Student ID '{$studentData['student_id']}' already exists.";
                    continue;
                }

                $user = User::create([
                    'name' => $studentData['name'],
                    'student_id' => $studentData['student_id'],
                    'course' => $request->course,
                    'year_level' => $request->year_level,
                    'section' => $request->section,
                    'email' => $studentData['student_id'] . '@pclu.edu',
                    'password' => Hash::make('password'),
                    'role' => 'student'
                ]);

                $created[] = $user;
            }

            if (count($errors) > 0 && count($created) === 0) {
                \DB::rollBack();
                return response()->json([
                    'message' => 'No students were registered.',
                    'errors' => $errors
                ], 422);
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