<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FacultyTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'faculty_id',
        'book_asset_id',
        'borrowed_at',
        'due_date',
        'returned_at',
        'processed_by',
        'penalty_amount',
        'payment_status',
        'payment_date',
        'remarks'
    ];

    /**
     * Cast dates properly
     */
    protected $casts = [
        'borrowed_at' => 'datetime',
        'due_date' => 'date',
        'returned_at' => 'datetime',
        'payment_date' => 'datetime',
        'penalty_amount' => 'decimal:2'
    ];

    /**
     * Relationship: Link to the Faculty
     */
    public function faculty()
    {
        return $this->belongsTo(Faculty::class);
    }

    /**
     * Relationship: Link to the Physical Copy (Asset)
     */
    public function bookAsset()
    {
        return $this->belongsTo(BookAsset::class);
    }

    /**
     * Relationship: Link to the admin who processed
     */
    public function processedByUser()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /**
     * Check if this transaction is overdue
     */
    public function getIsOverdueAttribute()
    {
        return is_null($this->returned_at) && $this->due_date < now();
    }

    /**
     * Get days overdue (0 if not overdue or returned)
     */
    public function getDaysOverdueAttribute()
    {
        if ($this->returned_at || $this->due_date >= now()) {
            return 0;
        }
        return now()->diffInDays($this->due_date);
    }
}
