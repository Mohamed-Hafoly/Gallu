<?php

namespace App\Models;

use Database\Factories\ImageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Attributes\SearchUsingFullText;
use Laravel\Scout\Builder as ScoutBuilder;
use Laravel\Scout\Searchable;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Image extends Model implements HasMedia
{
    /** @use HasFactory<ImageFactory> */
    use HasFactory, InteractsWithMedia, Prunable, Searchable, SoftDeletes;

    /**
     * The media collection holding the image's own uploaded file.
     */
    public const IMAGES_COLLECTION = 'images';

    /**
     * How long a binned image is kept before it is destroyed for good.
     *
     * The shortest window in the app, per req.txt. Hours rather than days
     * because that is how the requirement and the SPA's trash note both read.
     */
    public const RETENTION_HOURS = 24;

    /**
     * Alias for the join onto `users` that puts the creator's name inside
     * Scout's search group. Shared with toSearchableArray() so the two cannot
     * drift.
     */
    public const SEARCH_CREATOR = 'search_creator';

    /**
     * Keep in sync with frontend/src/composables/useValidationRules.ts.
     */
    public const ACCEPTED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'document_id',
        'title',
        'description',
    ];

    /**
     * The columns Scout searches.
     *
     * `description` is matched with MATCH ... AGAINST through the FULLTEXT index
     * its migration adds - so it matches whole *words*, ignores terms under
     * innodb_ft_min_token_size (3 by default) and anything on InnoDB's stopword
     * list, and finds nothing for a mid-word fragment. `title` is a `%term%`
     * LIKE, which is what keeps partial-title search working.
     *
     * The dotted key is left alone by qualifyColumn() and resolves against the
     * alias newScoutQuery() joins. Its value is never read - the database engine
     * takes only the keys and queries the tables directly - hence null rather
     * than a relation access that would be an N+1 if it ever ran.
     *
     * Two columns are deliberately absent. `id`, as on Document. And
     * `document_id`, which this listing used to match exactly for an all-digit
     * term: Scout can only LIKE a declared column, and `document_id LIKE '%1%'`
     * would make a search for "1" match documents 1, 10, 11 and 21 - the broken
     * filter the old comment here rejected. Keeping the exact form would mean
     * ORing it through the engine callback at the top level, beside this group
     * rather than inside it, which is the pattern Document::newScoutQuery()
     * explains at length. The gallery loses nothing: it is always pinned to one
     * document through the `document_id` *filter*, so a free-text id could
     * never narrow anything there.
     *
     * @return array<string, mixed>
     */
    #[SearchUsingFullText(['description'])]
    public function toSearchableArray(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            self::SEARCH_CREATOR.'.name' => null,
        ];
    }

    /**
     * The query Scout's database engine builds its search on.
     *
     * This exists so the creator's name can sit *inside* the engine's OR group
     * rather than beside it. That is a correctness requirement, not a tidiness
     * one: the engine appends both its search() callback and its query()
     * callback at the top level, so ORing the relation in either would compile
     * to
     *
     *     WHERE (title LIKE ? OR MATCH(description) ...) OR EXISTS(...) AND <scope>
     *
     * and AND binds tighter than OR. Eloquent does soften this - callScope()
     * nests the existing wheres before a local scope adds its own, so
     * scopeVisibleTo() would still wrap that OR today - but the safety is
     * incidental, holding only while visibleTo() stays a scope *and* stays
     * applied before the controller's plain filters. Joining here keeps every
     * clause inside the one group the engine already wraps, so the team scope
     * ANDs with the whole of it whatever is bolted on later.
     *
     * belongsTo, so the join is many-to-one and cannot duplicate a row - which
     * is why a join is safe here even though the creator *sort* in
     * ImageController::index() must stay a correlated subselect (it has to work
     * on an unsearched listing, where there is no join at all). Aliased, so it
     * cannot collide with the `users` that sort subselect brings into scope.
     */
    public function newScoutQuery(ScoutBuilder $builder): Builder
    {
        // Nothing to search means nothing to join: the gallery serves this
        // listing unsearched far more often than not.
        if (blank($builder->query)) {
            return static::query();
        }

        return static::query()
            // Required once anything is joined, or the joined `id` column
            // overwrites images.id as the row is hydrated.
            ->select($this->getTable().'.*')
            // Left, with the deleted_at test in the ON clause rather than a
            // where: a binned author's row still exists, so an inner join would
            // keep matching their name and search would surface content the
            // resource labels "[deleted]" - while the creator *sort*, an
            // Eloquent subselect that does carry the scope, files it under null.
            // Those three have to agree. Putting the test in a where instead
            // would drop the rows from the listing altogether rather than merely
            // making the name unmatchable.
            //
            // Nullable user_id since users became soft-deletable makes a left
            // join the correct shape regardless.
            ->leftJoin(
                'users as '.self::SEARCH_CREATOR,
                fn ($join) => $join
                    ->on(self::SEARCH_CREATOR.'.id', '=', 'images.user_id')
                    ->whereNull(self::SEARCH_CREATOR.'.deleted_at'),
            );
    }

    /**
     * The rows `model:prune` may destroy on this run.
     *
     * The clock alone is not enough. Document::booted() stamps an image with
     * the *document's* deleted_at when a document is binned, so an image
     * trashed as part of its document is indistinguishable by timestamp from
     * one binned on its own - and the document survives for seven days against
     * this image's twenty-four hours. Pruning on the timestamp alone would
     * empty a document's bin on day one and hand back an empty document on day
     * six, breaking the restore-whole guarantee Document::restoring() exists to
     * make.
     *
     * So an image is only ever prunable on its own clock while its document is
     * live. One trashed under its document waits, and is destroyed by
     * the document's own force-delete hook when its window runs out.
     *
     * whereHas('document') is the whole test: document() is a belongsTo onto a
     * soft-deleting model, so it carries Document's scope and matches live
     * documents only. images.document_id is NOT NULL, so there is no null case
     * to allow for here - the same shape as Document::prunable() one rung up,
     * whose team_id is NOT NULL too.
     *
     * No force-delete hook of its own: an image is the leaf of the hierarchy, and
     * forceDelete() on a HasMedia model already takes its file, conversions and
     * media row with it. category_image.image_id is cascadeOnDelete, so the
     * pivot goes too.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::where('deleted_at', '<=', now()->subHours(self::RETENTION_HOURS))
            ->whereHas('document');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Whether the owning document is itself in the bin.
     *
     * document() cannot answer this: it carries Document's soft-delete scope, so
     * it resolves to null for a trashed document - the same trap
     * ImagePolicy::teamOfImage() exists to avoid. images.document_id is NOT
     * NULL, so a null here always means trashed and never absent, exactly as
     * Document::teamIsTrashed() reads its team.
     *
     * How this image came to be in the bin makes no difference. One binned on
     * its own, whose document was deleted afterwards, is refused a restore just
     * the same — and comes back with that document, since Document::restoring
     * empties its whole bin.
     */
    public function documentIsTrashed(): bool
    {
        return Document::withTrashed()
            ->whereKey($this->document_id)
            ->whereNotNull('deleted_at')
            ->exists();
    }

    /**
     * Restrict a listing to what this user is allowed to see: everything for a
     * super-admin, otherwise only images in their own team's documents.
     *
     * An image has no team of its own — it inherits its document's, through a
     * NOT NULL foreign key. A team_id column here would be a second source of
     * truth that drifts the moment a document is moved between teams.
     *
     * A team-less non-super-admin matches nothing, which is correct: they have
     * no team whose entries they could be entitled to. Said outright rather
     * than passed down as a null, which Builder rewrites to `IS NULL` and so
     * happens to return the same empty set — but only because documents.team_id
     * is NOT NULL. See Document::scopeVisibleTo(), which does the same.
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->is_super_admin) {
            return;
        }

        $teamId = $user->teamAssignment()['team_id'] ?? null;

        if ($teamId === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereHas('document', fn (Builder $document) => $document->where('team_id', $teamId));
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::IMAGES_COLLECTION)
            ->singleFile()
            ->acceptsMimeTypes(self::ACCEPTED_MIME_TYPES);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->width(400)
            ->height(400)
            ->nonQueued();
    }
}
