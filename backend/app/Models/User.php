<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\RoleName;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Support\Config;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements HasMedia
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, InteractsWithMedia, Notifiable, Searchable;

    /**
     * The media collection holding the user's profile image.
     */
    public const AVATAR_COLLECTION = 'avatar';

    /**
     * Served straight out of `public/`, not the media disk, because it is a
     * fallback rather than an upload — see registerMediaCollections().
     */
    public const DEFAULT_AVATAR_PATH = 'images/default-avatar.jpg';

    /**
     * Memo for teamAssignment(), which UserResource hits for every listed row.
     *
     * @var array{team_id?: int, team_name?: string, role?: RoleName}|null
     */
    protected ?array $teamAssignment = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
        ];
    }

    /**
     * The columns Scout searches, LIKEd with a wildcard on either side by the
     * `database` engine.
     *
     * Only real `users` columns belong here: the engine qualifies every key
     * onto the model's own table, so a key naming anything else resolves to a
     * column that does not exist. That is why the team name is missing - it
     * lives behind `model_has_roles`, and rides into the same OR group through
     * the engine callback in UserController::index(). See
     * scopeOrWhereTeamNameLike().
     *
     * `id` is listed so the admin can jump straight to a row by the number the
     * table shows. The engine treats it specially rather than LIKEing it: when
     * the whole term is digits it matches the key by *equality* and drops the
     * LIKE for this column, so "145" finds user 145 and not user 1450. A term
     * that is not all digits leaves the id clause a LIKE that matches nothing,
     * which is the intended no-op.
     *
     * The values are unused by the `database` engine, which reads only the
     * keys and queries the table directly - there is no index to import or
     * keep in sync, and nothing to re-index when a team is renamed or binned.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
        ];
    }

    public function images(): HasMany
    {
        return $this->hasMany(Image::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * The role this user is presented as: the global flag first, then their
     * role within their team, falling back to Member for a team-less user.
     */
    public function role(): RoleName
    {
        if ($this->is_super_admin) {
            return RoleName::SuperAdmin;
        }

        return $this->teamAssignment()['role'] ?? RoleName::Member;
    }

    /**
     * This user's single team membership, or an empty array when they belong to
     * none.
     *
     * Queries `model_has_roles` directly rather than going through the `roles`
     * relation, which spatie filters by the ambient `getPermissionsTeamId()` —
     * that would answer "what is this user's role *in the current context*",
     * when the question here is "which team are they in at all". Same reasoning
     * as the super-admin predicate that preceded the `is_super_admin` column.
     *
     * Trashed teams are excluded, so a soft-deleted team reads as no membership
     * until it is restored.
     *
     * The name rides along so UserResource never has to load a Team per row;
     * scopeWithTeamAssignment() pre-selects all three on a listing.
     *
     * @return array{team_id?: int, team_name?: string, role?: RoleName}
     */
    public function teamAssignment(): array
    {
        if ($this->teamAssignment !== null) {
            return $this->teamAssignment;
        }

        // Populated by scopeWithTeamAssignment() on listings, so rendering a
        // page of users costs no query per row.
        if (array_key_exists('team_assignment_id', $this->attributes)) {
            return $this->teamAssignment = $this->attributes['team_assignment_id'] === null ? [] : [
                'team_id' => (int) $this->attributes['team_assignment_id'],
                'team_name' => (string) $this->attributes['team_assignment_name'],
                'role' => RoleName::from($this->attributes['team_assignment_role']),
            ];
        }

        $row = static::teamAssignmentQuery()
            ->where(self::pivotColumn(Config::morphKey()), $this->getKey())
            ->where(self::pivotColumn('model_type'), $this->getMorphClass())
            ->select('teams.id', 'teams.name', 'roles.name as role_name')
            ->first();

        return $this->teamAssignment = $row === null ? [] : [
            'team_id' => (int) $row->id,
            'team_name' => (string) $row->name,
            'role' => RoleName::from($row->role_name),
        ];
    }

    /**
     * The team this user belongs to, or null.
     *
     * Convenience over teamAssignment(), which already carries the id and name —
     * so UserResource deliberately does *not* use this, as hydrating a Team per
     * row would be a query per row on the listing.
     */
    public function team(): ?Team
    {
        $teamId = $this->teamAssignment()['team_id'] ?? null;

        return $teamId === null ? null : Team::find($teamId);
    }

    /**
     * Select the membership alongside the rows, so a listing costs no query per
     * user. Read back by teamAssignment().
     */
    public function scopeWithTeamAssignment(Builder $query): void
    {
        $select = fn (string $column) => fn (QueryBuilder $sub) => $this
            ->correlatedTeamAssignment($sub)
            ->select($column)
            ->limit(1);

        $query->select($this->getTable().'.*')->addSelect([
            'team_assignment_id' => $select('teams.id'),
            'team_assignment_name' => $select('teams.name'),
            'team_assignment_role' => $select('roles.name'),
        ]);
    }

    /**
     * Match a user whose live team's name contains the term.
     *
     * `or`, because this is the third clause of the users search, beside the
     * name and email ones toSearchableArray() declares. Scout's database engine
     * adds those two as a single nested group and then hands this scope's
     * caller - the engine callback in UserController::index() - the same query,
     * so an `or` here reads as `(name OR email) OR team`.
     *
     * An EXISTS rather than the `team_assignment_name` alias withTeamAssignment()
     * already selects: an alias is resolvable in ORDER BY - which is how the
     * team *sort* works - but not in WHERE. MySQL rejects it outright while
     * SQLite quietly allows it, and the suite runs on SQLite, so the difference
     * is one only the dev database would have shown.
     */
    public function scopeOrWhereTeamNameLike(Builder $query, string $search): void
    {
        $query->orWhereExists(
            fn (QueryBuilder $sub) => $this->correlatedTeamAssignment($sub)
                ->where('teams.name', 'like', "%{$search}%"),
        );
    }

    /**
     * teamAssignmentQuery(), tied to the row of `users` being read.
     *
     * Shared by the listing's select and by the search above so the two cannot
     * drift - the same reason teamAssignmentQuery() itself exists.
     */
    protected function correlatedTeamAssignment(QueryBuilder $sub): QueryBuilder
    {
        return self::teamAssignmentQuery($sub)
            ->whereColumn(self::pivotColumn(Config::morphKey()), $this->getTable().'.'.$this->getKeyName())
            ->where(self::pivotColumn('model_type'), $this->getMorphClass());
    }

    /**
     * The one definition of "which live team is this user assigned to", shared
     * by the per-model lookup and the listing scope so they cannot drift.
     */
    protected static function teamAssignmentQuery(?QueryBuilder $query = null): QueryBuilder
    {
        $pivot = Config::modelHasRolesTable();
        $roles = Config::rolesTable();

        return ($query ?? DB::query())
            ->from($pivot)
            ->join($roles, $roles.'.id', '=', self::pivotColumn(app(PermissionRegistrar::class)->pivotRole))
            ->join('teams', 'teams.id', '=', self::pivotColumn(Config::teamForeignKey()))
            ->whereNull('teams.deleted_at');
    }

    /**
     * Qualify a column on the role pivot, whose names are all configurable.
     */
    protected static function pivotColumn(string $column): string
    {
        return Config::modelHasRolesTable().'.'.$column;
    }

    /**
     * Put this user in a team with the given role, or remove them from any team
     * when `$team` is null. The only writer of membership.
     *
     * Existing rows are cleared first, which is what enforces one team per user
     * — spatie itself would happily hold an assignment per team.
     */
    public function assignToTeam(?Team $team, RoleName $role = RoleName::Member): void
    {
        $columns = config('permission.column_names');

        DB::table(config('permission.table_names')['model_has_roles'])
            ->where($columns['model_morph_key'], $this->getKey())
            ->where('model_type', $this->getMorphClass())
            ->delete();

        $this->teamAssignment = null;

        if ($team === null) {
            $this->unsetRelation('roles');

            return;
        }

        $previousTeamId = getPermissionsTeamId();
        setPermissionsTeamId($team->getKey());

        // Spatie caches roles per team context, so the relation has to be
        // dropped around the switch or a stale one answers for the wrong team.
        $this->unsetRelation('roles');
        $this->assignRole($role);

        setPermissionsTeamId($previousTeamId);
        $this->unsetRelation('roles');
    }

    /**
     * A user without an uploaded avatar falls back to the shipped default
     * image, so getFirstMediaUrl() never returns an empty string and the SPA
     * needs no placeholder of its own. Registered for the conversion too,
     * otherwise the thumb URL would come back empty.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::AVATAR_COLLECTION)
            ->singleFile()
            ->acceptsMimeTypes(Image::ACCEPTED_MIME_TYPES)
            ->useFallbackUrl(asset(self::DEFAULT_AVATAR_PATH))
            ->useFallbackUrl(asset(self::DEFAULT_AVATAR_PATH), 'thumb');
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->width(200)
            ->height(200)
            ->nonQueued();
    }

    /**
     * Store an uploaded file as this user's avatar.
     *
     * The collection is `singleFile()`, so this replaces any existing avatar
     * without an explicit clear. Shared by registration and profile updates.
     */
    public function setAvatarFromFile(UploadedFile $file): void
    {
        $this->addMedia($file)
            ->usingName($this->name)
            ->usingFileName(Str::uuid().'.'.$file->getClientOriginalExtension())
            ->toMediaCollection(self::AVATAR_COLLECTION);
    }

    /**
     * Apply the avatar half of a multipart form submission, if it asked for one.
     *
     * Clearing the collection is enough to "remove" an avatar — the collection
     * falls back to the default image. Shared by the Fortify profile action and
     * the admin user endpoint so both agree on what `remove_avatar` means; the
     * flag arrives over multipart as the string "1", hence filter_var.
     *
     * @param  array<string, mixed>  $input
     */
    public function applyAvatarInput(array $input): void
    {
        if (filter_var($input['remove_avatar'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $this->clearMediaCollection(self::AVATAR_COLLECTION);

            return;
        }

        if (! isset($input['avatar'])) {
            return;
        }

        $this->setAvatarFromFile($input['avatar']);
    }
}
