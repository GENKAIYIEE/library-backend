<?php

namespace App\Http\Controllers;

<<<<<<< HEAD
use Illuminate\Http\Request;
use App\Models\Attendance;
=======
use App\Models\AttendanceLog;
use App\Models\User;
use Illuminate\Http\Request;
>>>>>>> 4bbd6a008574794d40f208964421a8f7e8115d9e
use Carbon\Carbon;

class AttendanceController extends Controller
{
<<<<<<< HEAD
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
=======
    /**
     * PUBLIC: Log attendance via QR scan.
     * Prevents duplicate logs within 1 minute.
     */
    public function logAttendance(Request $request)
    {
        $request->validate([
            'student_id' => 'required|string',
        ]);

        // Find student by student_id
        $student = User::where('student_id', trim($request->student_id))
            ->where('role', 'student')
            ->first();

        if (!$student) {
            return response()->json([
                'success' => false,
                'message' => 'Student not found. Please check your QR code.',
            ], 404);
        }

        // Check for duplicate within 1 minute
        $recentLog = AttendanceLog::where('user_id', $student->id)
            ->where('logged_at', '>=', Carbon::now()->subMinute())
            ->first();

        if ($recentLog) {
            return response()->json([
                'success' => false,
                'message' => 'Already logged! Please wait before scanning again.',
                'student' => [
                    'name' => $student->name,
                    'student_id' => $student->student_id,
                    'course' => $student->course,
                    'profile_picture_url' => $student->profile_picture_url,
                ],
            ], 429);
        }

        // Create attendance log
        $log = AttendanceLog::create([
            'user_id' => $student->id,
            'logged_at' => Carbon::now(),
>>>>>>> 4bbd6a008574794d40f208964421a8f7e8115d9e
        ]);

        return response()->json([
            'success' => true,
<<<<<<< HEAD
            'message' => 'Attendance logged successfully.',
            'student' => $user,
            'logged_at' => $attendance->created_at->format('h:i A')
        ], 201);
=======
            'message' => 'Attendance recorded successfully!',
            'student' => [
                'name' => $student->name,
                'student_id' => $student->student_id,
                'course' => $student->course,
                'year_level' => $student->year_level,
                'profile_picture_url' => $student->profile_picture_url,
            ],
            'logged_at' => $log->logged_at->format('h:i A'),
        ]);
    }

    /**
     * ADMIN: Get all attendance logs with pagination.
     */
    public function index(Request $request)
    {
        $query = AttendanceLog::with([
            'user' => function ($query) {
                $query->select('id', 'name', 'student_id', 'course', 'year_level', 'profile_picture');
            }
        ])
            ->orderBy('logged_at', 'desc');

        // Filter by date range
        if ($request->has('from')) {
            $query->whereDate('logged_at', '>=', $request->from);
        }
        if ($request->has('to')) {
            $query->whereDate('logged_at', '<=', $request->to);
        }

        // Filter by student_id
        if ($request->has('student_id')) {
            $query->whereHas('user', function ($q) use ($request) {
                $q->where('student_id', 'like', '%' . $request->student_id . '%');
            });
        }

        $logs = $query->paginate(50);

        // Append profile_picture_url to each user
        $logs->getCollection()->each(function ($log) {
            if ($log->user) {
                $log->user->append('profile_picture_url');
            }
        });

        return response()->json($logs);
    }

    /**
     * ADMIN: Get today's attendance logs for real-time display.
     */
    public function today(Request $request)
    {
        $logs = AttendanceLog::with([
            'user' => function ($query) {
                $query->select('id', 'name', 'student_id', 'course', 'year_level', 'profile_picture');
            }
        ])
            ->whereDate('logged_at', Carbon::today())
            ->orderBy('logged_at', 'desc')
            ->limit(100)
            ->get();

        // Append profile_picture_url to each user
        $logs->each(function ($log) {
            if ($log->user) {
                $log->user->append('profile_picture_url');
            }
        });

        return response()->json([
            'logs' => $logs,
            'count' => $logs->count(),
            'date' => Carbon::today()->format('F j, Y'),
        ]);
>>>>>>> 4bbd6a008574794d40f208964421a8f7e8115d9e
    }
}
