<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;

   protected $fillable = [
        'user_id',
        'book_asset_id',
        'borrowed_at',
        'due_date',
        'returned_at',
        'processed_by',
        'penalty_amount' // <--- NEW LINE
    ];

    // Link to the Student
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Link to the Physical Copy (Asset)
    public function bookAsset()
    {
        return $this->belongsTo(BookAsset::class);
    }
}