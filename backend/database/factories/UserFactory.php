<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            // Stated rather than left to the column default, so an in-memory
            // instance reads false instead of null before it is refetched.
            'is_super_admin' => false,
            // Same reason, and load-bearing under Model::shouldBeStrict: a
            // factory instance handed straight to actingAs() is never refetched,
            // so UserResource reading $this->deleted_at would throw
            // MissingAttributeException rather than serialize a live user.
            'deleted_at' => null,
        ];
    }

    /**
     * A global super-admin. Not a spatie role — see the `is_super_admin` column.
     */
    public function superAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_super_admin' => true,
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
