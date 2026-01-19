<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class AuthController extends Controller
{
    // LOGIN FUNCTION - Supports username (can also be email for backwards compatibility)
    public function login(Request $request)
    {
        // 1. Validate the input
        $fields = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string'
        ]);

        $loginIdentifier = $fields['username'];

        // 2. Find user by username first, then try email (backwards compatibility)
        $user = User::where('username', $loginIdentifier)->first();

        if (!$user) {
            // Try by email for backwards compatibility with existing admin accounts
            $user = User::where('email', $loginIdentifier)->first();
        }

        // 3. Check if user exists and password is correct
        if (!$user || !Hash::check($fields['password'], $user->password)) {
            return response()->json([
                'message' => 'Invalid login credentials'
            ], 401);
        }

        // 4. Check if user is an admin
        if ($user->role !== 'admin') {
            return response()->json([
                'message' => 'Access denied. Admin accounts only.'
            ], 403);
        }

        // 5. Create a Token
        $token = $user->createToken('myapptoken')->plainTextToken;

        // 6. Return the User & Token to the Frontend
        return response()->json([
            'user' => $user,
            'token' => $token
        ], 200);
    }

    // LOGOUT FUNCTION
    public function logout(Request $request)
    {
        auth()->user()->tokens()->delete();

        return response()->json([
            'message' => 'Logged out'
        ]);
    }
}