<?php

namespace App\Models;

use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Attributes\SearchUsingFullText;
use Laravel\Scout\Builder as ScoutBuilder;
use Laravel\Scout\Searchable;

/**
 * A titled group of existing images.
 *
 * Deliberately not a HasMedia model: a document owns no file of its own, it
 * references Image records that already carry their own upload, categories and
 * creator. A spatie media collection could not express this — collection_name
 * is a flat string scoped to one owning model, so collections cannot nest, be
 * shared between documents, or carry a title of their own.
 */
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory, Searchable, SoftDeletes;

    /**
     * Alias for the join onto `users` that puts the creator's name inside
     * Scout's search group. Shared with toSearchableArray() so the two cannot
     * drift.
     */
    public const SEARCH_CREATOR = 'search_creator';

    /**
     * Alias for the join onto `teams`, as above.
     */
    public const SEARCH_TEAM = 'search_team';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'team_id',
        'title',
        'description',
    ];

    /**
     * Keep a document's images in step with its own trashed state.
     *
     * They live here rather than in DocumentController because the invariant —
     * an image cannot outlive the document that gives it a place in the
     * hierarchy, which is what the images.document_id migration says — has to
     * hold for the bulk delete, for tinker, and for anything added later, not
     * just for one controller action. Team::booted() is the same pair one level
     * up, and reaches these through delete() and restore().
     */
    protected static function booted(): void
    {
        // `deleted`, not `deleting`: deleted_at is not stamped until after the
        // save, and the cascade copies it so the two agree.
        static::deleted(function (Document $document): void {
            // A hard delete is the database's job — images.document_id is
            // ON DELETE CASCADE. Repeating it here would be a second, slower
            // truth, and it would miss rows that are already soft-deleted.
            if ($document->isForceDeleting()) {
                return;
            }

            // The relation carries Image's own soft-delete scope, so this only
            // touches live images. An image already in the bin keeps the
            // deleted_at it has, which is all this needs to do: the restore
            // below takes everything back regardless of how it got there.
            $document->images()->update(['deleted_at' => $document->deleted_at]);
        });

        // `restoring`, not `restored`, so a failure aborts the whole restore
        // rather than leaving the document back and its images behind.
        static::restoring(function (Document $document): void {
            // Every image in this document's bin, not only the ones its own
            // delete put there. Restoring a document restores the document
            // *whole*: an image binned separately beforehand comes back with
            // it, and can be deleted again by hand if that was not wanted.
            //
            // This is what retired images.trashed_with_document, whose only
            // purpose was telling the two apart.
            //
            // A raw update rather than restore() per image: one statement, and
            // it fires no Image events.
            $document->images()->onlyTrashed()->update(['deleted_at' => null]);
        });
    }

    /**
     * The columns Scout searches.
     *
     * `description` is matched with MATCH ... AGAINST through the FULLTEXT
     * index its migration adds - so it matches whole *words*, ignores terms
     * under innodb_ft_min_token_size (3 by default), and finds nothing for a
     * mid-word fragment. Everything else here is a `%term%` LIKE, `title`
     * included, which is what keeps partial-title search working.
     *
     * The dotted keys are left alone by qualifyColumn() and resolve against the
     * aliases newScoutQuery() joins. Their values are never read - the database
     * engine takes only the keys and queries the tables directly - hence null
     * rather than a relation access that would be an N+1 if it ever ran.
     *
     * `id` is deliberately absent, unlike User's. Scout takes its searchable
     * columns from this method, which belongs to the class rather than to the
     * query, so an id clause could not be limited to the admin table - the
     * gallery calls the same endpoint. Adding it in DocumentController instead
     * is worse: it would have to OR at the top level, where it would escape
     * scopeVisibleTo()'s AND and let a member fetch any document by guessing a
     * number. Read the note on newScoutQuery() before reconsidering.
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
            self::SEARCH_TEAM.'.name' => null,
        ];
    }

    /**
     * The query Scout's database engine builds its search on.
     *
     * This exists so the creator and team names can sit *inside* the engine's
     * OR group rather than beside it. That is a correctness requirement, not a
     * tidiness one: the engine appends both its search() callback and its
     * query() callback at the top level, so ORing the two relations in either
     * of those would compile to
     *
     *     WHERE (title LIKE ? OR MATCH(description) ...) OR EXISTS(...) AND team_id = ?
     *
     * and AND binds tighter than OR. Eloquent does soften this: callScope()
     * nests the existing wheres before a local scope adds its own, so
     * scopeVisibleTo() as written today would still wrap that OR. But that
     * safety is incidental - it holds only while visibleTo() remains a scope
     * *and* stays the last thing applied, and an OR added after it leaks
     * immediately, which DocumentCrudTest's "keeps a search inside the callers
     * team" demonstrates. Joining here instead keeps every clause inside the one
     * group the engine already wraps, so the team scope ANDs with the whole of
     * it whatever else is bolted on later.
     *
     * Both joins are many-to-one, so neither can duplicate a row - which is why
     * a join is safe here even though the *sorts* in DocumentController::index()
     * must stay correlated subselects. The team side is a LEFT join because
     * documents.team_id is nullable, and carries no deleted_at filter on
     * purpose: a document that went down with its team is still findable by
     * that team's name, which is what the withTrashed() on the old orWhereHas
     * did.
     *
     * Aliased rather than joined bare, so nothing collides with the `users` and
     * `teams` those sort subselects bring into scope.
     */
    public function newScoutQuery(ScoutBuilder $builder): Builder
    {
        // Nothing to search means nothing to join: the listing is served
        // unsearched far more often than not.
        if (blank($builder->query)) {
            return static::query();
        }

        return static::query()
            // Required once anything is joined, or the joined `id` columns
            // overwrite documents.id as the row is hydrated.
            ->select($this->getTable().'.*')
            ->join(
                'users as '.self::SEARCH_CREATOR,
                self::SEARCH_CREATOR.'.id', '=', 'documents.user_id',
            )
            ->leftJoin(
                'teams as '.self::SEARCH_TEAM,
                self::SEARCH_TEAM.'.id', '=', 'documents.team_id',
            );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(Image::class);
    }

    /**
     * Whether the owning team is itself in the bin.
     *
     * team() cannot answer this: it carries Team's soft-delete scope, so it
     * resolves to null for a trashed team exactly as it does for a null
     * team_id — and those two must never be confused here. A document with no
     * team may be restored; one whose team is trashed may not, because a live
     * document always has a live team.
     *
     * The document's own trashed state cannot answer it either: how a document
     * came to be in the bin makes no difference here. One binned on its own,
     * whose team was deleted afterwards, is refused just the same — and comes
     * back with that team, since a team's restore empties its whole bin.
     */
    public function teamIsTrashed(): bool
    {
        return $this->team_id !== null
            && Team::withTrashed()
                ->whereKey($this->team_id)
                ->whereNotNull('deleted_at')
                ->exists();
    }

    /**
     * Restrict a listing to what this user is allowed to see: everything for a
     * super-admin, otherwise only their own team's documents.
     *
     * A team-less non-super-admin matches nothing. Note DatabaseSeeder seeds no
     * teams, so on a fresh install every non-super-admin sees an empty list —
     * that is the rule working, not a bug.
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->is_super_admin) {
            return;
        }

        $query->where('team_id', $user->teamAssignment()['team_id'] ?? null);
    }
}
