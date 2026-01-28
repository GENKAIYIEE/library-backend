<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Attendance;
use Carbon\Carbon;

class AttendanceController extends Controller
{
    public function index()
    {
        $attendance = Attendance::with('user')
            ->orderBy('created_at', 'desc')
            ->paginate(15);
        return response()->json($attendance);
    }

    public function today()
    {
        $query = Attendance::whereDate('created_at', Carbon::today());

        $count = $query->count();

        $logs = $query->with('user')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($log) {
                return [
                    'id' => $log->id,
                    'user' => $log->user,
                    'logged_at' => $log->created_at, // timestamps are automatically cast to Carbon
                ];
            });

        return response()->json([
            'count' => $count,
            'date' => Carbon::now()->format('F j, Y'),
            'logs' => $logs
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id'
        ]);

        $attendance = Attendance::create([
            'user_id' => $request->user_id
        ]);

        return response()->json($attendance, 201);
    }

    public function storePublic(Request $request)
    {
        $request->validate([
            'student_id' => 'required|string'
        ]);

        // Find user by student_id
        $user = \App\Models\User::where('student_id', $request->student_id)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Student ID not found in system.'
            ], 404);
        }

        // Check if already logged today
        $existing = Attendance::where('user_id', $user->id)
            ->whereDate('created_at', Carbon::today())
            ->first();

        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'Attendance already logged for today.',
                'student' => $user,
                'logged_at' => $existing->created_at->format('h:i A')
            ], 200); // 200 OK because we want to show the profile, just with a warning
        }

        // Create Attendance
        $attendance = Attendance::create([
            'user_id' => $user->id
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Attendance logged successfully.',
            'student' => $user,
            'logged_at' => $attendance->created_at->format('h:i A')
        ], 201);
    }
}
