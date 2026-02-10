<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class MonthlyFinancialRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'year',
        'month',
        'total_fines',
        'total_collected',
        'total_pending',
        'late_returns',
        'finalized_at',
    ];

    protected $casts = [
        'total_fines' => 'decimal:2',
        'total_collected' => 'decimal:2',
        'total_pending' => 'decimal:2',
        'finalized_at' => 'datetime',
    ];

    /**
     * Get or create a record for a specific month
     */
    public static function getOrCreateForMonth(int $year, int $month): self
    {
        return self::firstOrCreate(
            ['year' => $year, 'month' => $month],
            [
                'total_fines' => 0,
                'total_collected' => 0,
                'total_pending' => 0,
                'late_returns' => 0,
            ]
        );
    }

    /**
     * Recalculate financials for a specific month from transactions
     */
    public static function recalculateForMonth(int $year, int $month): self
    {
        $record = self::getOrCreateForMonth($year, $month);

        // Calculate from transactions table
        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate = date('Y-m-t', strtotime($startDate));

        $stats = Transaction::query()
            ->whereNotNull('returned_at')
            ->whereDate('returned_at', '>=', $startDate)
            ->whereDate('returned_at', '<=', $endDate)
            ->select([
                DB::raw('COALESCE(SUM(penalty_amount), 0) as total_fines'),
                DB::raw('COALESCE(SUM(CASE WHEN payment_status = "paid" THEN penalty_amount ELSE 0 END), 0) as total_collected'),
                DB::raw('COALESCE(SUM(CASE WHEN payment_status = "pending" THEN penalty_amount ELSE 0 END), 0) as total_pending'),
                DB::raw('COUNT(CASE WHEN penalty_amount > 0 THEN 1 END) as late_returns'),
            ])
            ->first();

        $record->update([
            'total_fines' => $stats->total_fines ?? 0,
            'total_collected' => $stats->total_collected ?? 0,
            'total_pending' => $stats->total_pending ?? 0,
            'late_returns' => $stats->late_returns ?? 0,
        ]);

        return $record->fresh();
    }

    /**
     * Get formatted month name
     */
    public function getMonthNameAttribute(): string
    {
        return date('F', mktime(0, 0, 0, $this->month, 10));
    }

    /**
     * Check if this month is finalized (locked)
     */
    public function getIsFinalizedAttribute(): bool
    {
        return $this->finalized_at !== null;
    }

    /**
     * Get all historical records (past months)
     */
    public static function getHistory(int $limit = 12)
    {
        return self::orderByDesc('year')
            ->orderByDesc('month')
            ->limit($limit)
            ->get();
    }

    /**
     * Get current month record with real-time data
     */
    public static function getCurrentMonth()
    {
        $year = (int) date('Y');
        $month = (int) date('n');

        return self::recalculateForMonth($year, $month);
    }
}
