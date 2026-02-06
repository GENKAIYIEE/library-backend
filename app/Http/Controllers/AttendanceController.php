<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\User;
use Illuminate\Http\Request;
use Carbon\Carbon;

class AttendanceController extends Controller
{
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
        ]);

        return response()->json([
            'success' => true,
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
     * ADMIN: Get attendance logs for a specific date (defaults to today).
     * Supports historical date queries via ?date=YYYY-MM-DD parameter.
     */
    public function today(Request $request)
    {
        $query = AttendanceLog::with([
            'user' => function ($query) {
                $query->select('id', 'name', 'student_id', 'course', 'year_level', 'profile_picture');
            }
        ]);

        // Check for date range
        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereDate('logged_at', '>=', $request->start_date)
                  ->whereDate('logged_at', '<=', $request->end_date);
            
            $dateLabel = Carbon::parse($request->start_date)->format('M j') . ' - ' . Carbon::parse($request->end_date)->format('M j, Y');
            $dateIso = $request->end_date; // Default to end date for picker sync or keep as is
        } else {
            // Default to single date (today or specified)
            $dateInput = $request->input('date', Carbon::today()->toDateString());
            $parsedDate = Carbon::parse($dateInput);
            $query->whereDate('logged_at', $parsedDate);
            
            $dateLabel = $parsedDate->format('F j, Y');
            $dateIso = $parsedDate->toDateString();
        }

        $query->orderBy('logged_at', 'asc');

        // Check if we need all records for reporting
        if ($request->has('all') && $request->all == 'true') {
            $logs = $query->get();
            
             // Append profile_picture_url to each user
            $logs->each(function ($log) {
                if ($log->user) {
                    $log->user->append('profile_picture_url');
                }
            });

            return response()->json([
                'logs' => $logs,
                'count' => $logs->count(),
                'date' => $dateLabel,
                'date_iso' => $dateIso,
            ]);
        }

        $logs = $query->paginate(10); // Default pagination

        // Append profile_picture_url to each user
        $logs->getCollection()->each(function ($log) {
            if ($log->user) {
                $log->user->append('profile_picture_url');
            }
        });

        return response()->json([
            'logs' => $logs->items(),
            'pagination' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
            'count' => $logs->total(), // Use total count instead of collection count
            'date' => $dateLabel,
            'date_iso' => $dateIso, // For frontend date picker sync
        ]);
    }
}
