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
     * The only model events in the app. They live here rather than in
     * DocumentController::destroy() because the invariant — an image cannot
     * outlive the document that gives it a place in the hierarchy, which is what
     * the images.document_id migration says — has to hold for the bulk delete,
     * for tinker, and for anything added later, not just for one controller
     * action.
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
            // touches images that are still live: an image already in the bin on
            // its own is not the document's to take, and must not be its to
            // return either. The flag is what records that difference — see the
            // migration for why deleted_at cannot be used to infer it.
            $document->images()->update([
                'deleted_at' => $document->deleted_at,
                'trashed_with_document' => true,
            ]);
        });

        // `restoring`, not `restored`, so a failure aborts the whole restore
        // rather than leaving the document back and its images behind.
        static::restoring(function (Document $document): void {
            // One raw update rather than restore() plus a second write to clear
            // the flag — and it fires no Image events, which is what keeps
            // Image::booted()'s own flag-clearing out of this path.
            $document->images()
                ->onlyTrashed()
                ->where('trashed_with_document', true)
                ->update(['deleted_at' => null, 'trashed_with_document' => false]);
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
