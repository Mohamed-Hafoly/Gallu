<?php

namespace App\Models;

use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

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
    use HasFactory, SoftDeletes;

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
