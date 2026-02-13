<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;


use App\Models\BookTitle;

class BookTitleFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = BookTitle::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'title' => $this->faker->sentence(3),
            'subtitle' => $this->faker->optional()->sentence(2),
            'author' => $this->faker->name(),
            'isbn' => $this->faker->isbn13(),
            'accession_no' => $this->faker->unique()->numberBetween(10000, 99999),
            'lccn' => $this->faker->optional()->numerify('###-########'),
            'issn' => $this->faker->optional()->numerify('####-####'),
            'category' => $this->faker->randomElement(['Fiction', 'Non-Fiction', 'Science', 'History', 'Technology', 'Arts']),
            'publisher' => $this->faker->company(),
            'place_of_publication' => $this->faker->city(),
            'published_year' => $this->faker->year(),
            'copyright_year' => $this->faker->year(),
            'call_number' => $this->faker->bothify('##.### ???'),
            'physical_description' => $this->faker->optional()->sentence(),
            'edition' => $this->faker->optional()->randomDigitNotNull(),
            'series' => $this->faker->optional()->word(),
            'volume' => $this->faker->optional()->randomDigitNotNull(),
            'pages' => $this->faker->numberBetween(100, 1000),
            'price' => $this->faker->randomFloat(2, 10, 200),
            'book_penalty' => 0.00,
            'language' => 'English',
            'description' => $this->faker->paragraph(),
            'location' => 'Main Library',
            'image_path' => null, // Or a placeholder URL if needed
            'cover_image' => null,
        ];
    }
}

