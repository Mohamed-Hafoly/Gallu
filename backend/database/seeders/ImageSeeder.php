<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Document;
use App\Models\Image;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Images, with real attached media and random categories, mirroring
 * ImageController::store()'s upload flow.
 *
 * Driven by DocumentSeeder rather than looping over every user itself: an image
 * belongs to a document, so who owns it and which document it lands in are
 * decisions about team structure that only the caller knows.
 */
class ImageSeeder extends Seeder
{
    /**
     * How many distinct placeholder files to generate and share.
     *
     * The images are dev scenery, so they only have to differ enough to tell
     * apart on screen - and every one of them costs a full GD encode of a
     * 480k-pixel canvas. Generating a pool once and reusing it across the whole
     * seed turns a few hundred encodes into a dozen, each media row still
     * landing under its own uuid filename on disk.
     *
     * The per-image `thumb` conversion still runs either way, and is the part
     * that cannot be shared.
     */
    private const PLACEHOLDER_POOL = 12;

    /**
     * Absolute paths of the generated placeholders, or null before the first
     * seedInto() call. Cleaned up by discardPlaceholders().
     *
     * @var list<string>|null
     */
    private ?array $placeholders = null;

    /**
     * Every category, loaded once.
     *
     * @var Collection<int, Category>|null
     */
    private ?Collection $categories = null;

    /**
     * Not used directly - DocumentSeeder calls seedInto() per document.
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
        // Both memoised on the instance rather than re-read per document: the
        // categories were a query per call, and the placeholders an encode per
        // image. One ImageSeeder is shared across the whole run.
        $this->categories ??= Category::all();
        $this->placeholders ??= $this->generatePlaceholders();

        Image::factory()
            ->count($count)
            ->for($owner)
            ->for($document)
            ->create()
            ->each(function (Image $image): void {
                $image->addMedia($this->placeholders[array_rand($this->placeholders)])
                    // preservingOriginal(), or the first image to use a given
                    // placeholder would move it off disk and leave every later
                    // one adding a file that is no longer there.
                    ->preservingOriginal()
                    ->usingName($image->title)
                    ->usingFileName(Str::uuid().'.jpg')
                    ->toMediaCollection(Image::IMAGES_COLLECTION);

                if ($this->categories->isNotEmpty()) {
                    $image->categories()->sync(
                        $this->categories->random(min(3, $this->categories->count()))->pluck('id')
                    );
                }
            });
    }

    /**
     * Remove the generated placeholders.
     *
     * Called by TeamSeeder once the run is over. Necessary precisely
     * *because* of preservingOriginal() above: left to itself addMedia() moves
     * the source and unlinks it (FileAdder::preserveOriginal defaults to
     * false), which is what used to clean these up one image at a time. Once
     * the pool is shared that cleanup has to happen once, at the end, here.
     */
    public function discardPlaceholders(): void
    {
        foreach ($this->placeholders ?? [] as $path) {
            // The .jpg *and* the extension-less tempnam() stub beside it:
            // tempnam() creates its own file, and appending '.jpg' makes a
            // second path that imagejpeg() then writes. Only the .jpg was ever
            // collected before, by the move addMedia() does - the stub leaked
            // one file per image.
            @unlink($path);
            @unlink(substr($path, 0, -strlen('.jpg')));
        }

        $this->placeholders = null;
    }

    /**
     * @return list<string>
     */
    private function generatePlaceholders(): array
    {
        return array_map(
            fn () => $this->generatePlaceholderImage(),
            range(1, self::PLACEHOLDER_POOL),
        );
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
