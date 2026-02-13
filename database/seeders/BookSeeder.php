<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;


use App\Models\BookTitle;
use App\Models\BookAsset;

class BookSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Change count to 100
        BookTitle::factory()
            ->count(100)
            ->create()
            ->each(function ($bookTitle) {
                // Create 1-3 assets for each book title
                $assetsCount = rand(1, 3);
                BookAsset::factory()
                    ->count($assetsCount)
                    ->create([
                        'book_title_id' => $bookTitle->id,
                        // Ensure asset code corresponds to book title if needed, or just random unique
                    ]);
            });
    }
}

