<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Roles first: anything assigning one needs them to exist already.
        $this->call(RoleSeeder::class);

        User::factory()->create([
            'name' => 'John Doe',
            'email' => 'johndoe@example.com',
            'password' => '12345678',
            'is_super_admin' => true,
        ]);


        $this->call(CategorySeeder::class);
        $this->call(ImageSeeder::class);
        User::factory()->count(120)->create();
    }
}
