<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BookAsset extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'book_title_id',
        'asset_code',
        'building',
        'aisle',
        'shelf',
        'status'
    ];

    // Link to the main Title (e.g., "Intro to PHP")
    public function bookTitle()
    {
        return $this->belongsTo(BookTitle::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function facultyTransactions()
    {
        return $this->hasMany(FacultyTransaction::class);
    }

    // Use hasOne combined with latest() to strictly get the single most recent transaction per asset
    // efficient for eager loading
    public function latestTransaction()
    {
        return $this->hasOne(Transaction::class)->latest('id');
    }
}