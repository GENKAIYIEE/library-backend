<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Transaction;
use Carbon\Carbon;

class AnalyticsController extends Controller
{
    /**
     * Get monthly borrowing trends for the last 6 months
     */
    public function monthlyTrends()
    {
        // Get last 30 days
        $days = collect([]);
        for ($i = 29; $i >= 0; $i--) {
            $days->push(Carbon::now()->subDays($i));
        }

        $labels = $days->map(function ($date) {
            return $date->format('M d');
        });

        $data = $days->map(function ($date) {
            return Transaction::whereDate('borrowed_at', $date->toDateString())
                ->count();
        });

        return response()->json([
            'labels' => $labels,
            'data' => $data
        ]);
    }

    /**
     * Get popularity of categories based on borrowing history
     */
    public function categoryPopularity()
    {
        // Join Transactions -> Book Assets -> Book Titles to get Category
        $categories = Transaction::join('book_assets', 'transactions.book_asset_id', '=', 'book_assets.id')
            ->join('book_titles', 'book_assets.book_title_id', '=', 'book_titles.id')
            ->select('book_titles.category', DB::raw('count(*) as total'))
            ->groupBy('book_titles.category')
            ->orderByDesc('total')
            ->limit(5) // Top 5 categories
            ->get();

        // If fewer than 5, we show what we have. 
        // We might want to group "Others" if we had many, but Top 5 is good.

        return response()->json([
            'labels' => $categories->pluck('category'),
            'data' => $categories->pluck('total')
        ]);
    }
}
