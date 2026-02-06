<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class MonthlyStatistic extends Model
{
    use HasFactory;

    protected $fillable = [
        'year',
        'month',
        'range_start',
        'range_end',
        'count'
    ];

    protected $casts = [
        'year' => 'integer',
        'month' => 'integer',
        'range_start' => 'integer',
        'range_end' => 'integer',
        'count' => 'integer',
    ];

    /**
     * Increment the statistics count for a given call number
     * 
     * Example: Call Number "243" → Range 200-299 → Increment count for current month
     *
     * @param string $callNumber The book's call number
     * @param Carbon|null $date Optional date (defaults to now)
     * @return bool Success status
     */
    public static function incrementForCallNumber(string $callNumber, ?Carbon $date = null): bool
    {
        // Parse the call number to extract the numeric portion
        if (!preg_match('/^(\d{1,3})/', trim($callNumber), $matches)) {
            // Cannot parse call number - skip silently
            return false;
        }

        $number = (int) $matches[1];
        
        // Ensure it's within valid Dewey range (0-999)
        if ($number < 0 || $number > 999) {
            return false;
        }

        // Calculate range: 243 → range_start=200, range_end=299
        $rangeStart = (int) floor($number / 100) * 100;
        $rangeEnd = $rangeStart + 99;

        // Get current date
        $date = $date ?? Carbon::now();
        $year = $date->year;
        $month = $date->month;

        // Find or create the record, then increment
        $stat = self::firstOrCreate(
            [
                'year' => $year,
                'month' => $month,
                'range_start' => $rangeStart,
            ],
            [
                'range_end' => $rangeEnd,
                'count' => 0,
            ]
        );

        $stat->increment('count');

        \Log::info("MonthlyStatistic: Call Number '{$callNumber}' → Range {$rangeStart}-{$rangeEnd}, Month {$month}/{$year}, New Count: " . ($stat->count));

        return true;
    }

    /**
     * Get statistics for a specific year, organized by month and range
     */
    public static function getYearlyStats(int $year): array
    {
        $stats = self::where('year', $year)->get();

        $matrix = [];
        $ranges = [
            [0, 99], [100, 199], [200, 299], [300, 399], [400, 499],
            [500, 599], [600, 699], [700, 799], [800, 899], [900, 999]
        ];

        // Initialize matrix
        foreach ($ranges as [$start, $end]) {
            $rangeKey = "{$start}-{$end}";
            for ($m = 1; $m <= 12; $m++) {
                $matrix[$rangeKey][$m] = 0;
            }
        }

        // Populate with actual data
        foreach ($stats as $stat) {
            $rangeKey = "{$stat->range_start}-{$stat->range_end}";
            if (isset($matrix[$rangeKey])) {
                $matrix[$rangeKey][$stat->month] = $stat->count;
            }
        }

        return $matrix;
    }
}
