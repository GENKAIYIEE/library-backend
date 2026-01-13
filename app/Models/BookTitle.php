<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BookTitle extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'title',
        'author',
        'isbn',
        'category',
        'description',
        'cover_image'
    ];

    // Relationship: One Title has many physical copies (Assets)
    public function assets()
    {
        return $this->hasMany(BookAsset::class);
    }
}