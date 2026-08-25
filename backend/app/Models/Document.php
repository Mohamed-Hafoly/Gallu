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
