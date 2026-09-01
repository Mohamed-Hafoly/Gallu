<?php

namespace App\Models;

use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Support\Config;

/**
 * Registered as spatie's team model via `config('permission.models.team')`,
 * which is what makes User::teams() resolvable.
 */
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory, Prunable, SoftDeletes;

    /**
     * How long a binned team is kept before it is destroyed for good.
     */
    public const RETENTION_DAYS = 30;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'description',
    ];

    /**
     * Keep a team's documents in step with its own trashed state.
     *
     * The second link of the same chain Document::booted() starts: an image
     * cannot outlive its document, and a document cannot outlive the team that
     * gives it a place in the hierarchy and its whole visibility rule — see
     * Document::scopeVisibleTo(), and User::teamAssignment(), which reads a
     * trashed team as no membership at all. Without this a deleted team left
     * its documents live and dangling, reported as team-less and visible to
     * nobody but a super-admin.
     *
     * In the model rather than in TeamController for the reason spelled out on
     * Document::booted(): the invariant has to hold for tinker and for anything
     * added later, not just for one controller action.
     */
    protected static function booted(): void
    {
        // The force-delete half, and both parts of it exist because the
        // database gets this wrong in opposite directions.
        //
        // The documents *would* be cascaded by documents.team_id, and that is
        // the problem: a SQL cascade fires no model events, so it runs straight
        // on through images.document_id and orphans every image file and
        // `media` row on disk. Going through the model instead runs Document's
        // own force-delete hook, which is where that is handled.
        //
        // The membership rows would not be cascaded at all. model_has_roles
        // carries a foreign key on role_id only — team_id and model_id are
        // plain indexed columns — so the rows naming this team would simply
        // outlive it, and User::teamAssignment() reads that table directly.
        // Deleted here rather than through members(), which is a morphedByMany
        // and hands back users rather than the pivot rows this has to remove.
        //
        // On `deleting` rather than Prunable's pruning() so it holds for a
        // force delete from anywhere — the prune, a test, tinker — and not only
        // when model:prune happens to be the caller.
        static::deleting(function (Team $team): void {
            if (! $team->isForceDeleting()) {
                return;
            }

            $team->documents()->withTrashed()->get()
                ->each(fn (Document $document) => $document->forceDelete());

            DB::table(Config::modelHasRolesTable())
                ->where(Config::teamForeignKey(), $team->getKey())
                ->delete();
        });

        // `deleted`, not `deleting`: deleted_at is not stamped until after the
        // save, and the cascade is only meaningful once it is.
        static::deleted(function (Team $team): void {
            // documents.team_id is cascadeOnDelete, so a hard delete takes
            // them with it in SQL — there is nothing here to repeat. Note the
            // cascade is the database's, not this one's, and so fires no model
            // events: that is why the force-delete hook below cannot lean on
            // it either.
            if ($team->isForceDeleting()) {
                return;
            }

            // One document at a time rather than one bulk UPDATE, unlike
            // Document::booted(): delete() is what fires Document's own cascade
            // onto the images. A bulk update fires no events, so the images
            // would be left live under a trashed document — and copying the
            // image half up here would be the second source of truth that
            // Document::booted() exists to avoid. Teams are few and this runs
            // once per delete, so the loop costs nothing that matters.
            //
            // The relation carries Document's soft-delete scope, so this only
            // touches live documents; one already in the bin keeps the
            // deleted_at it has, and comes back with the rest on restore.
            $team->documents()->each(fn (Document $document) => $document->delete());
        });

        // `restoring`, not `restored`, so a failure aborts the whole restore
        // rather than leaving the team back and its documents behind.
        static::restoring(function (Team $team): void {
            // Everything in this team's bin, not only what its own delete put
            // there. Restoring a team restores the team *whole*, the same rule
            // Document::restoring() follows for its images: a document binned
            // separately beforehand comes back with the team, and can be deleted
            // again by hand if that was not wanted.
            //
            // restore() rather than a bulk update, so Document::restoring fires
            // and empties each document's own image bin in turn.
            $team->documents()
                ->onlyTrashed()
                ->each(fn (Document $document) => $document->restore());
        });
    }

    /**
     * The rows `model:prune` may destroy on this run.
     *
     * Unconditional, unlike Document and Image: a team is the top of the
     * hierarchy, so there is no parent bin it could be waiting inside.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::where('deleted_at', '<=', now()->subDays(self::RETENTION_DAYS));
    }

    /**
     * Who created the team. Nullable, so deleting the creator leaves the team.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The documents filed under this team.
     *
     * Carries Document's own soft-delete scope, so the cascade above only ever
     * claims live rows; the restore side re-widens it with onlyTrashed().
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * The team's members.
     *
     * Membership is implicit in spatie's model: a user belongs to this team by
     * holding a role row scoped to it, so this reads `model_has_roles` rather
     * than a pivot of its own. The inverse of User::teams().
     */
    public function members(): MorphToMany
    {
        return $this->morphedByMany(
            User::class,
            'model',
            Config::modelHasRolesTable(),
            Config::teamForeignKey(),
            Config::morphKey(),
        )->distinct();
    }
}
