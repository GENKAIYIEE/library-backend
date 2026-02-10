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
        'count',
        'user_type'
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
     * @param string $userType 'student' or 'faculty'
     * @param Carbon|null $date Optional date (defaults to now)
     * @return bool Success status
     */
    public static function incrementForCallNumber(string $callNumber, string $userType = 'student', ?Carbon $date = null): bool
    {
        // Parse the call number to extract the numeric portion
        if (!preg_match('/^(\d{1,3})/', trim($callNumber), $matches)) {
            // Cannot parse call number - skip silently
            return false;
        }

        $number = (int) $matches[1];
        
        // Fetch configured ranges
        $ranges = \App\Models\LibrarySetting::getValue('statistics_ranges', []);
        
        // Default fallbacks if settings empty
        if (empty($ranges)) {
            for ($i = 0; $i < 10; $i++) {
                $start = $i * 100;
                $ranges[] = ['start' => $start, 'end' => $start + 99];
            }
        }

        $rangeStart = null;
        $rangeEnd = null;

        // Find matching range
        foreach ($ranges as $range) {
            if ($number >= $range['start'] && $number <= $range['end']) {
                $rangeStart = $range['start'];
                $rangeEnd = $range['end'];
                break;
            }
        }

        // If no range found (e.g. out of bounds), ignore or use a default "Uncategorized" bucket
        // For now, we return false to skip recording
        if ($rangeStart === null) {
            return false;
        }

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
                'user_type' => $userType
            ],
            [
                'range_end' => $rangeEnd,
                'count' => 0,
            ]
        );

        $stat->increment('count');

        \Log::info("MonthlyStatistic: [{$userType}] Call Number '{$callNumber}' → Range {$rangeStart}-{$rangeEnd}, Month {$month}/{$year}, New Count: " . ($stat->count));

        return true;
    }
}
