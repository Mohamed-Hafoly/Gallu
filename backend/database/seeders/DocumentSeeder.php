<?php

namespace Database\Seeders;

use App\Models\Document;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * A team's documents, and the images inside them.
 *
 * Driven by TeamSeeder rather than looping over documents itself, for the same
 * reason ImageSeeder is driven by this one: documents.team_id is NOT NULL and
 * the visibility rules are all about *who* owns a row inside a team, so the
 * caller is the only one that knows the cast.
 */
class DocumentSeeder extends Seeder
{
    /**
     * Inclusive range of documents to put in a team, and of images to put in
     * each. Randomised per document so the listings have something to sort and
     * page through rather than a wall of identical rows.
     */
    private const DOCUMENTS_PER_TEAM = [3, 5];

    private const IMAGES_PER_DOCUMENT = [4, 7];

    /**
     * Not used directly - TeamSeeder calls seedInto() per team.
     */
    public function run(): void
    {
        //
    }

    /**
     * Fill `$team` with documents owned by `$admin`, their images spread across
     * `$members`.
     *
     * @param  Collection<int, User>  $members
     * @return Collection<int, Document>
     */
    public function seedInto(Team $team, User $admin, Collection $members, ImageSeeder $imageSeeder): Collection
    {
        $documents = Document::factory()
            ->count(random_int(...self::DOCUMENTS_PER_TEAM))
            ->for($admin)
            // Passed explicitly, and it must stay that way: DocumentFactory
            // defaults team_id to Team::factory(), so forgetting this does not
            // fail - it silently files every document under a throwaway team of
            // its own, which is invisible until a listing comes back empty.
            ->create(['team_id' => $team->id]);

        foreach ($documents as $index => $document) {
            // Rotated rather than random so every team reliably has images from
            // more than one member, which is what the "cannot edit a teammate's
            // image" rule needs to be visible in the UI at all.
            $owner = $members[$index % $members->count()];

            $imageSeeder->seedInto($document, $owner, random_int(...self::IMAGES_PER_DOCUMENT));
        }

        return $documents;
    }
}
