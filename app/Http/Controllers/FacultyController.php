<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Faculty;
use App\Models\FacultyTransaction;
use Illuminate\Support\Facades\DB;

class FacultyController extends Controller
{
    /**
     * Generate the next sequential faculty ID.
     * Format: FAC-YYYY-XXXX (e.g., FAC-2026-0001)
     */
    private function generateFacultyId()
    {
        $year = date('Y');
        $prefix = "FAC-{$year}-";

        // Find the highest existing number for this year
        $latest = Faculty::withTrashed()
            ->where('faculty_id', 'like', $prefix . '%')
            ->orderByRaw('CAST(SUBSTRING(faculty_id, -4) AS UNSIGNED) DESC')
            ->first();

        if ($latest) {
            $lastNumber = (int) substr($latest->faculty_id, -4);
            $nextNumber = $lastNumber + 1;
        } else {
            $nextNumber = 1;
        }

        return $prefix . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
    }

    /**
     * 1. GET ALL FACULTIES
     */
    public function index()
    {
        return Faculty::orderBy('name')->get();
    }

    /**
     * 2. REGISTER A NEW FACULTY
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone_number' => 'nullable|string|max:50',
            'department' => 'required|string|max:100',
        ]);

        // Check for duplicate email if provided
        if ($request->email) {
            $existingEmail = Faculty::where('email', $request->email)->first();
            if ($existingEmail) {
                return response()->json([
                    'message' => 'A faculty member with this email already exists.'
                ], 422);
            }
        }

        // Generate faculty ID
        $facultyId = $this->generateFacultyId();

        $faculty = Faculty::create([
            'faculty_id' => $facultyId,
            'name' => $request->name,
            'email' => $request->email,
            'phone_number' => $request->phone_number,
            'department' => $request->department,
            'status' => 'active',
        ]);

        return response()->json([
            'message' => 'Faculty member registered successfully!',
            'faculty' => $faculty
        ], 201);
    }

    /**
     * 3. UPDATE FACULTY
     */
    public function update(Request $request, $id)
    {
        $faculty = Faculty::findOrFail($id);

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone_number' => 'nullable|string|max:50',
            'department' => 'sometimes|required|string|max:100',
            'status' => 'sometimes|in:active,inactive',
        ]);

        // Check for duplicate email if changing
        if ($request->email && $request->email !== $faculty->email) {
            $existingEmail = Faculty::where('email', $request->email)
                ->where('id', '!=', $id)
                ->first();
            if ($existingEmail) {
                return response()->json([
                    'message' => 'A faculty member with this email already exists.'
                ], 422);
            }
        }

        $faculty->update($request->only([
            'name',
            'email',
            'phone_number',
            'department',
            'status'
        ]));

        return response()->json([
            'message' => 'Faculty updated successfully!',
            'faculty' => $faculty->fresh()
        ]);
    }

    /**
     * 4. DELETE FACULTY (Soft Delete)
     */
    public function destroy($id)
    {
        $faculty = Faculty::findOrFail($id);

        // Check for active loans
        $activeLoans = $faculty->activeLoans()->count();
        if ($activeLoans > 0) {
            return response()->json([
                'message' => "Cannot delete faculty with {$activeLoans} active loan(s). Please return all books first."
            ], 422);
        }

        $faculty->delete();
        return response()->json(['message' => 'Faculty removed successfully.']);
    }

    /**
     * 5. GET DEPARTMENT SUMMARY
     * Returns all unique departments with faculty count and active loans
     */
    public function getDepartmentSummary()
    {
        $departments = Faculty::where('status', 'active')
            ->select('department')
            ->selectRaw('COUNT(*) as total_faculty')
            ->selectRaw('(SELECT COUNT(*) FROM faculty_transactions ft 
                          JOIN faculties f ON ft.faculty_id = f.id 
                          WHERE f.department = faculties.department 
                          AND ft.returned_at IS NULL) as active_loans')
            ->groupBy('department')
            ->orderBy('department')
            ->get();

        return response()->json($departments);
    }

    /**
     * 6. GET FACULTIES BY DEPARTMENT
     * With pagination and search
     */
    public function getFacultiesByDepartment(Request $request, $department)
    {
        $perPage = $request->input('per_page', 20);
        $search = $request->input('search', '');

        $query = Faculty::where('department', $department)
            ->where('status', 'active');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('faculty_id', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $faculties = $query->orderBy('name')
            ->paginate($perPage);

        // Add computed fields to each faculty
        foreach ($faculties as $faculty) {
            $faculty->total_borrowed = $faculty->transactions()->count();
            $faculty->active_loans = $faculty->activeLoans()->count();
            $faculty->overdue = $faculty->has_overdue;
        }

        return response()->json($faculties);
    }

    /**
     * 7. GET FACULTY HISTORY
     */
    public function history($id)
    {
        $faculty = Faculty::findOrFail($id);

        $transactions = FacultyTransaction::where('faculty_id', $id)
            ->with(['bookAsset.bookTitle'])
            ->orderByDesc('borrowed_at')
            ->get()
            ->map(function ($tx) {
                $book = $tx->bookAsset?->bookTitle;
                return [
                    'id' => $tx->id,
                    'book_title' => $book?->title ?? 'Unknown',
                    'author' => $book?->author ?? 'Unknown',
                    'asset_code' => $tx->bookAsset?->asset_code ?? 'N/A',
                    'borrowed_at' => $tx->borrowed_at,
                    'due_date' => $tx->due_date,
                    'returned_at' => $tx->returned_at,
                    'is_overdue' => $tx->is_overdue,
                    'penalty_amount' => $tx->penalty_amount,
                    'payment_status' => $tx->payment_status,
                    'remarks' => $tx->remarks,
                ];
            });

        return response()->json([
            'faculty' => $faculty,
            'transactions' => $transactions,
            'stats' => [
                'total_borrowed' => $transactions->count(),
                'active_loans' => $transactions->whereNull('returned_at')->count(),
            ]
        ]);
    }

    /**
     * 8. SEARCH FACULTIES (for circulation lookup)
     */
    public function search(Request $request)
    {
        $query = $request->input('q', '');

        if (strlen($query) < 2) {
            return response()->json([]);
        }

        $faculties = Faculty::where('status', 'active')
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                    ->orWhere('faculty_id', 'like', "%{$query}%")
                    ->orWhere('email', 'like', "%{$query}%");
            })
            ->limit(10)
            ->get()
            ->map(function ($faculty) {
                return [
                    'id' => $faculty->id,
                    'faculty_id' => $faculty->faculty_id,
                    'name' => $faculty->name,
                    'email' => $faculty->email,
                    'department' => $faculty->department,
                    'active_loans' => $faculty->activeLoans()->count(),
                ];
            });

        return response()->json($faculties);
    }

    /**
     * 9. GET FACULTY BY ID (for circulation)
     */
    public function show($id)
    {
        $faculty = Faculty::findOrFail($id);

        return response()->json([
            'id' => $faculty->id,
            'faculty_id' => $faculty->faculty_id,
            'name' => $faculty->name,
            'email' => $faculty->email,
            'phone_number' => $faculty->phone_number,
            'department' => $faculty->department,
            'status' => $faculty->status,
            'active_loans' => $faculty->activeLoans()->count(),
            'total_borrowed' => $faculty->transactions()->count(),
            'has_overdue' => $faculty->has_overdue,
        ]);
    }
}
