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
            // Email is optional for now, or you can make fake ones like "id@school.edu"
            'email' => 'nullable|email' 
        ]);

        $user = User::create([
            'name' => $request->name,
            'student_id' => $request->student_id,
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
}