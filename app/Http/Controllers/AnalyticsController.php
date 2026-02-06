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
        // Show distribution of book titles by category (Inventory)
        $categories = \App\Models\BookTitle::select('category', DB::raw('count(*) as total'))
            ->groupBy('category')
            ->orderByDesc('total')
            ->get();

        return response()->json([
            'labels' => $categories->pluck('category'),
            'data' => $categories->pluck('total')
        ]);
    }
}
