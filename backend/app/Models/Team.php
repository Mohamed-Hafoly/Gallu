<?php

namespace App\Models;

use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Permission\Support\Config;

/**
 * Registered as spatie's team model via `config('permission.models.team')`,
 * which is what makes User::teams() resolvable.
 */
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory, SoftDeletes;

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
     * Who created the team. Nullable, so deleting the creator leaves the team.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
