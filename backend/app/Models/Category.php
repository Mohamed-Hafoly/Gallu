<?php

namespace App\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory, Prunable, SoftDeletes;

    /**
     * How long a binned category is kept before it is destroyed for good.
     *
     * req.txt names a window for every other model but not this one; 30 days
     * matches users and teams, the two it sits beside in the admin section.
     */
    public const RETENTION_DAYS = 30;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'name_en',
        'name_ar',
    ];

    /**
     * Clear the pivot before a force delete.
     *
     * category_image.category_id is the one foreign key in this schema with no
     * onDelete clause, so it is RESTRICT: destroying a category that still has
     * images attached raises an integrity constraint violation rather than
     * cascading. detach() with no arguments removes every pivot row for this
     * parent, trashed images included — it writes against the pivot table
     * directly and so is not filtered by Image's soft-delete scope, which is
     * what this needs.
     *
     * On `deleting` rather than Prunable's pruning(), which fires only when
     * model:prune is the caller: a force delete from tinker would otherwise
     * throw. A soft delete keeps the pivot, so a restore is lossless — see
     * ImageController::syncCategories(), which re-appends trashed category ids
     * for the same reason.
     */
    protected static function booted(): void
    {
        static::deleting(function (Category $category): void {
            if (! $category->isForceDeleting()) {
                return;
            }

            $category->images()->detach();
        });
    }

    /**
     * The rows `model:prune` may destroy on this run.
     *
     * Nothing conditional here, unlike Image and Document: a category is not
     * inside anything, so there is no parent whose own bin it has to wait for.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::where('deleted_at', '<=', now()->subDays(self::RETENTION_DAYS));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function images(): BelongsToMany
    {
        return $this->belongsToMany(Image::class);
    }
}
