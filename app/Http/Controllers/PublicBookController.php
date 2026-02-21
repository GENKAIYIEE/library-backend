<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\BookTitle;

class PublicBookController extends Controller
{
    /**
     * Display a listing of the resource for the public catalog.
     * Supports search and pagination.
     * Excludes books that are damaged or lost (unavailable for circulation).
     */
    public function index(Request $request)
    {
        $limit = $request->query('limit', 12);
        $search = $request->query('search');
        $category = $request->query('category');

        $query = BookTitle::withCount([
            'assets as available_copies' => function ($query) {
                $query->where('status', 'available');
            },
            'assets as borrowed_copies' => function ($query) {
                $query->where('status', 'borrowed');
            },
            'assets as damaged_copies' => function ($query) {
                $query->where('status', 'damaged');
            }
        ])
            // Only include books that have at least one asset that is NOT lost/archived (include damaged)
            ->whereHas('assets', function ($q) {
                $q->whereIn('status', ['available', 'borrowed', 'damaged']);
            });

        // Apply Category Filter
        if ($category && $category !== 'All') {
            $query->where('category', $category);
        }

        // Apply Search Filter
        if ($search) {
            $sanitizedSearch = addcslashes($search, '%_');
            $query->where(function ($q) use ($sanitizedSearch) {
                $q->where('title', 'like', "%$sanitizedSearch%")
                    ->orWhere('subtitle', 'like', "%$sanitizedSearch%")
                    ->orWhere('author', 'like', "%$sanitizedSearch%")
                    ->orWhere('isbn', 'like', "%$sanitizedSearch%")
                    ->orWhere('call_number', 'like', "%$sanitizedSearch%");
            });
        }

        // Latest additions first
        $books = $query->latest()->paginate($limit);

        return response()->json($books);
    }

    /**
     * Display the specified resource with detailed location info.
     */
    public function show($id)
    {
        $book = BookTitle::withCount([
            'assets as available_copies' => function ($query) {
                $query->where('status', 'available');
            }
        ])
            // Include only available assets to show location
            ->with([
                'assets' => function ($query) {
                    $query->where('status', 'available')
                        ->select('id', 'book_title_id', 'building', 'aisle', 'shelf', 'asset_code', 'status');
                }
            ])
            ->findOrFail($id);

        return response()->json($book);
    }

    /**
     * Get all categories with book counts.
     */
    public function categories()
    {
        $categories = BookTitle::select('category')
            ->selectRaw('count(*) as count')
            ->groupBy('category')
            ->orderBy('count', 'desc')
            ->get();

        return response()->json($categories);
    }
}
