<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * Store a new user (admin or student)
     */
    public function store(Request $request)
    {
        $accountType = $request->input('account_type'); // 'admin' or 'student'

        // Validation rules based on account type
        $rules = [
            'account_type' => 'required|in:admin,student',
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
        ];

        if ($accountType === 'admin') {
            $rules['username'] = 'required|string|max:50|unique:users,username';
            $rules['password'] = 'required|string|min:8';
            $rules['permissions'] = 'required|in:full_access,read_only';
        } else {
            // Student
            $rules['student_id'] = 'required|string|unique:users,student_id';
            $rules['course'] = 'required|string';
            $rules['year_level'] = 'required|integer|min:1|max:6';
        }

        $validated = $request->validate($rules);

        // Create user based on account type
        $userData = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role' => $accountType,
            'status' => 'active',
        ];

        if ($accountType === 'admin') {
            $userData['username'] = $validated['username'];
            $userData['password'] = Hash::make($validated['password']);
            $userData['permissions'] = $validated['permissions'];
        } else {
            // Student - set a default password (can be changed later)
            $userData['student_id'] = $validated['student_id'];
            $userData['course'] = $validated['course'];
            $userData['year_level'] = $validated['year_level'];
            $userData['section'] = $request->input('section', '');
            $userData['password'] = Hash::make('student123'); // Default password for students
        }

        $user = User::create($userData);

        return response()->json([
            'message' => $accountType === 'admin'
                ? "Administrator '{$user->name}' created successfully!"
                : "Student '{$user->name}' created successfully!",
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'student_id' => $user->student_id,
            ]
        ], 201);
    }

    /**
     * Check if email/username/student_id is unique
     */
    public function checkUnique(Request $request)
    {
        $field = $request->input('field'); // email, username, or student_id
        $value = $request->input('value');
        $excludeId = $request->input('exclude_id'); // For edit mode

        if (!in_array($field, ['email', 'username', 'student_id'])) {
            return response()->json(['error' => 'Invalid field'], 400);
        }

        $query = User::where($field, $value);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        $exists = $query->exists();

        return response()->json([
            'field' => $field,
            'value' => $value,
            'is_unique' => !$exists,
            'message' => $exists ? ucfirst(str_replace('_', ' ', $field)) . ' already exists' : null
        ]);
    }

    /**
     * Get list of admin users
     */
    public function index(Request $request)
    {
        $role = $request->query('role');

        $query = User::query();

        if ($role) {
            $query->where('role', $role);
        }

        $users = $query->orderBy('created_at', 'desc')->get();

        return response()->json($users);
    }
}
