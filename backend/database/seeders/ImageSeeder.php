<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Document;
use App\Models\Image;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Images, with real attached media and random categories, mirroring
 * ImageController::store()'s upload flow.
 *
 * Driven by DatabaseSeeder rather than looping over every user itself: an image
 * belongs to a document now, so who owns it and which document it lands in are
 * decisions about team structure that only the caller knows.
 */
class ImageSeeder extends Seeder
{
    /**
     * Not used directly — DatabaseSeeder calls seedInto() per document.
     */
    public function run(): void
    {
        //
    }

    /**
     * Put `$count` images into `$document`, owned by `$owner`.
     */
    public function seedInto(Document $document, User $owner, int $count = 4): void
    {
        $categories = Category::all();

        Image::factory()
            ->count($count)
            ->for($owner)
            ->for($document)
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
