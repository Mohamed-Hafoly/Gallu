<?php

namespace Database\Factories;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Team>
 */
class TeamFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Unique because the column is: a plain company() would collide as
            // soon as a test creates a handful.
            'name' => fake()->unique()->company(),
            'description' => fake()->sentence(),
            'user_id' => User::factory(),
        ];
    }

    /**
     * A team whose creator has since been deleted.
     */
    public function creatorless(): static
    {
        return $this->state(fn (array $attributes) => ['user_id' => null]);
    }
}
