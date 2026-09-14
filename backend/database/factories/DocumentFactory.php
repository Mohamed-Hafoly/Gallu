<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
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
            // documents.team_id is NOT NULL, so the factory makes a team rather
            // than leaving the column unset - the same reason ImageFactory makes
            // a document. Tests about teams pass an explicit id and never reach
            // this; tests about ownership get a throwaway team they can ignore.
            'team_id' => Team::factory(),
            'title' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
        ];
    }
}
