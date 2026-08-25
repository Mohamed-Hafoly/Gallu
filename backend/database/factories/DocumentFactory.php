<?php

namespace Database\Factories;

use App\Models\Document;
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
            // Left null by default: team stamping is the controller's job and
            // most tests are about ownership, not teams.
            'team_id' => null,
            'title' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
        ];
    }
}
