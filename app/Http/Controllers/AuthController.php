<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;

class AuthController extends Controller
{
    // LOGIN FUNCTION
    public function login(Request $request)
    {
        // 1. Validate the input
        $fields = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string'
        ]);

        // 2. Check email & password
        if (!Auth::attempt($fields)) {
            return response()->json([
                'message' => 'Invalid login credentials'
            ], 401);
        }

        // 3. Get the user
        $user = User::where('email', $fields['email'])->first();

        // 4. Create a Token
        $token = $user->createToken('myapptoken')->plainTextToken;

        // 5. Return the User & Token to the Frontend
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