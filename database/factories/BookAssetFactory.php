<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;


use App\Models\BookAsset;
use App\Models\BookTitle;

class BookAssetFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = BookAsset::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'book_title_id' => BookTitle::factory(),
            'asset_code' => $this->faker->unique()->bothify('ASSET-#####'),
            'building' => 'Main Building',
            'aisle' => $this->faker->randomDigitNotNull(),
            'shelf' => $this->faker->randomDigitNotNull(),
            'status' => 'Available',
        ];
    }
}

