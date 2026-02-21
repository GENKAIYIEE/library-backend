<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Mail;
use App\Mail\UserCredentialsMail;
use Illuminate\Support\Str;

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
            'email' => ['required', 'email', Rule::unique('users')->whereNull('deleted_at')],
        ];

        if ($accountType === 'admin') {
            $rules['username'] = ['required', 'string', 'max:50', Rule::unique('users')->whereNull('deleted_at')];
            // Password is optional if we are generating it
            $rules['password'] = 'nullable|string|min:8';
            $rules['permissions'] = 'required|in:full_access,read_only';
        } else {
            // Student
            $rules['student_id'] = ['required', 'string', Rule::unique('users')->whereNull('deleted_at')];
            $rules['course'] = 'required|string';
            $rules['year_level'] = 'required|integer|min:1|max:6';
        }

        $validated = $request->validate($rules);

        // Generate password if not provided for admin
        $rawPassword = null;
        if ($accountType === 'admin') {
            $rawPassword = $request->input('password');
            if (empty($rawPassword)) {
                $rawPassword = Str::random(12); // Generate secure random password
            }
        }

        // Create user based on account type
        $userData = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role' => $accountType,
            'status' => 'active',
        ];

        if ($accountType === 'admin') {
            $userData['username'] = $validated['username'];
            $userData['password'] = Hash::make($rawPassword);
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

        // Send email credential if requested or if password was generated
        $notifyUser = $request->boolean('notify_user', true); // Default to true
        $emailSent = false;

        if ($accountType === 'admin' && $notifyUser) {
            try {
                Mail::to($user->email)->send(new UserCredentialsMail($user, $rawPassword));
                $emailSent = true;
            } catch (\Exception $e) {
                // Log error but don't fail the request
                // \Log::error("Failed to send credentials to {$user->email}: " . $e->getMessage());
            }
        }

        return response()->json([
            'message' => $accountType === 'admin'
                ? "Administrator '{$user->name}' created successfully!" . ($emailSent ? " Credentials sent via email." : "")
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

        $query = User::where($field, $value)->whereNull('deleted_at');

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
    /**
     * Get list of admin users with pagination and search
     */
    public function index(Request $request)
    {
        $role = $request->query('role');
        $search = $request->query('search'); // New: Search functionality
        $perPage = $request->query('per_page', 10); // Default 10 items per page

        $query = User::query();

        // EXCLUDE STUDENTS - User Management is for Admins/Staff only
        $query->where('role', '!=', 'student');

        // Filter by role if provided
        if ($role) {
            $query->where('role', $role);
        }

        // Search by name, email, or username
        if ($search) {
            $sanitizedSearch = addcslashes($search, '%_');
            $query->where(function ($q) use ($sanitizedSearch) {
                $q->where('name', 'like', "%{$sanitizedSearch}%")
                    ->orWhere('email', 'like', "%{$sanitizedSearch}%")
                    ->orWhere('username', 'like', "%{$sanitizedSearch}%");
            });
        }

        // Exclude current user (optional, but good practice for "Manage Users")
        // $query->where('id', '!=', auth()->id());

        $users = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json($users);
    }

    /**
     * Update user details
     */
    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);

        // Validation rules
        $rules = [
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', Rule::unique('users')->ignore($user->id)->whereNull('deleted_at')],
        ];

        if ($user->role === 'admin') {
            $rules['username'] = ['required', 'string', 'max:50', Rule::unique('users')->ignore($user->id)->whereNull('deleted_at')];
            $rules['permissions'] = 'required|in:full_access,read_only';
            $rules['password'] = 'nullable|string|min:8'; // Optional password update
        }

        if ($user->role === 'student') {
            $rules['student_id'] = ['required', 'string', Rule::unique('users')->ignore($user->id)->whereNull('deleted_at')];
            $rules['course'] = 'required|string';
            $rules['year_level'] = 'required|integer|min:1|max:6';
            $rules['password'] = 'nullable|string|min:8';
        }

        $validated = $request->validate($rules);

        // Update basic info
        $user->name = $validated['name'];
        $user->email = $validated['email'];

        if ($user->role === 'admin') {
            $user->username = $validated['username'];
            $user->permissions = $validated['permissions'];
        } else {
            $user->student_id = $validated['student_id'];
            $user->course = $validated['course'];
            $user->year_level = $validated['year_level'];
            if ($request->has('section')) {
                $user->section = $request->input('section');
            }
        }

        // Update password if provided
        if (!empty($request->input('password'))) {
            $user->password = Hash::make($request->input('password'));

            // Optionally send email about password change
            // $notifyUser = $request->boolean('notify_user', false);
            // if ($notifyUser) { ... }
        }

        $user->save();

        return response()->json([
            'message' => "User '{$user->name}' updated successfully.",
            'user' => $user
        ]);
    }

    /**
     * Delete user
     */
    public function destroy($id)
    {
        $user = User::findOrFail($id);

        if ($user->id === auth()->id()) {
            return response()->json(['message' => 'You cannot delete your own account.'], 403);
        }

        $user->delete(); // Soft delete if trait enabled, or hard delete

        return response()->json(['message' => "User '{$user->name}' has been deleted."]);
    }
}
