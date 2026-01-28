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
        'subtitle',
        'author',
        'isbn',
        'accession_no',
        'lccn',
        'issn',
        'category',
        'publisher',
        'place_of_publication',
        'published_year',
        'copyright_year',
        'call_number',
        'physical_description',
        'edition',
        'series',
        'volume',
        'pages',
        'price',
        'book_penalty',
        'language',
        'description',
        'location',
        'image_path',
        'cover_image'
    ];

    // Relationship: One Title has many physical copies (Assets)
    public function assets()
    {
        return $this->hasMany(BookAsset::class);
    }
}