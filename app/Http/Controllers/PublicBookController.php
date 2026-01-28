<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\BookTitle;

class PublicBookController extends Controller
{
    /**
     * Display a listing of the resource for the public catalog.
     * Supports search and pagination.
     */
    public function index(Request $request)
    {
        $limit = $request->query('limit', 12);
        $search = $request->query('search');

        $query = BookTitle::withCount([
            'assets as available_copies' => function ($query) {
                $query->where('status', 'available');
            }
        ]);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%$search%")
                    ->orWhere('subtitle', 'like', "%$search%") // Added subtitle search
                    ->orWhere('author', 'like', "%$search%")
                    ->orWhere('category', 'like', "%$search%")
                    ->orWhere('isbn', 'like', "%$search%");
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
