<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\Image;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Image>
 */
class ImageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            // Every image belongs to a document, so the factory makes one rather
            // than leaving a NOT NULL column unset.
            'document_id' => Document::factory(),
            'title' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
        ];
    }
}
