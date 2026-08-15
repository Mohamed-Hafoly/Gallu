<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Image;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ImageSeeder extends Seeder
{
    /**
     * Seed a handful of images (with real attached media and random categories)
     * for every existing user, mirroring ImageController::store()'s upload flow.
     */
    public function run(): void
    {
        $categories = Category::all();

        User::all()->each(function (User $user) use ($categories) {
            Image::factory()
                ->count(8)
                ->for($user)
                ->create()
                ->each(function (Image $image) use ($categories) {
                    $path = $this->generatePlaceholderImage();

                    $image->addMedia($path)
                        ->usingName($image->title)
                        ->usingFileName(Str::uuid().'.jpg')
                        ->toMediaCollection(Image::IMAGES_COLLECTION);

                    if ($categories->isNotEmpty()) {
                        $image->categories()->sync(
                            $categories->random(min(3, $categories->count()))->pluck('id')
                        );
                    }
                });
        });
    }

    private function generatePlaceholderImage(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'seed-image').'.jpg';

        $canvas = imagecreatetruecolor(800, 600);
        $color = imagecolorallocate($canvas, random_int(0, 255), random_int(0, 255), random_int(0, 255));
        imagefill($canvas, 0, 0, $color);
        imagejpeg($canvas, $path);
        imagedestroy($canvas);

        return $path;
    }
}
