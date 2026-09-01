<?php

namespace App\Http\Controllers;

use App\Enums\RoleName;
use App\Http\Requests\IndexUserRequest;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;

/**
 * Users management, super-admin only via UserPolicy.
 *
 * Unlike CategoryController this pages server-side: the admin screen uses
 * VDataTableServer, so page, sort and search all arrive as query parameters and
 * the response carries the `meta.total` the table needs for its footer.
 */
class UserController extends Controller
{
    /**
     * Columns the table may sort on. Anything else is a 422 from
     * IndexUserRequest rather than an injectable orderBy.
     *
     * @var list<string>
     */
    public const SORTABLE = ['id', 'name', 'email', 'role', 'team', 'created_at', 'updated_at'];

    /**
     * The API's name for the `is_super_admin` column, which is what the table
     * sorts by when the role header is clicked.
     */
    public const ROLE_SORT = 'role';

    /**
     * The API's name for the team's name, which lives neither on `users` nor on
     * a column at all - see index(), which sorts on the select alias
     * User::scopeWithTeamAssignment() already puts on every row.
     */
    public const TEAM_SORT = 'team';

    /**
     * What the table's "All" option sends for per_page.
     */
    public const ALL_PER_PAGE = -1;

    public function index(IndexUserRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', User::class);

        $trashed = $request->input('trashed');

        // Authorised rather than silently ignored, as on documents and images: a
        // caller who forges the flag should be told no, not handed a quietly
        // different list they cannot distinguish from an empty bin.
        if ($trashed !== null) {
            Gate::authorize('viewTrashed', User::class);
        }

        $search = $request->string('search')->trim()->toString();
        $sortBy = $request->input('sort_by') ?: 'id';
        $direction = $request->input('sort_order') ?: 'asc';

        // Which columns are searched is User::toSearchableArray()'s to say, and
        // the engine wraps them in one OR group. The team name is inside that
        // group through the join User::newScoutQuery() adds - deliberately not
        // through search()'s callback, which appends at the top level where the
        // engine's own deleted_at test would bind to only the last branch. Read
        // the note on newScoutQuery() before moving it back.
        //
        // An empty term is no search at all: the engine leaves the query alone
        // when the term is blank, so this needs no conditional.
        $builder = User::search($search)
            // The bin, on the Scout builder rather than inside query() below -
            // constrainForSoftDeletes() runs after that callback and would
            // override an Eloquent-level onlyTrashed() there. Users reached this
            // path only when they became soft-deletable; the live listing needs
            // nothing, since search() seeds __soft_deleted = 0 on its own.
            //
            // Unaffected by the engine callback above: constrainForSoftDeletes()
            // reads the builder's wheres directly rather than going through
            // addAdditionalConstraints(), which is what the callback suppresses.
            ->when($trashed === 'with', fn ($builder) => $builder->withTrashed())
            ->when($trashed === 'only', fn ($builder) => $builder->onlyTrashed())
            // The engine applies this straight to the database query, so both
            // eager loads land before the rows are read.
            //
            // The resource's avatar_url / has_avatar read the media relation,
            // which is a query per row without the first; role and team both
            // come off teamAssignment(), which is a query per row without the
            // second. withTeamAssignment() also selects the alias the team sort
            // below orders on.
            ->query(fn (Builder $query) => $query->with('media')->withTeamAssignment())
            // Neither `role` nor `team` is a column on `users`. `role` is the
            // API's name for is_super_admin, so ascending puts plain users (0)
            // before super-admins (1); `team` sorts on the name the cell shows,
            // through the alias withTeamAssignment() has already selected -
            // both databases resolve a select alias in ORDER BY, the same way
            // DocumentController sorts on withCount()'s images_count.
            //
            // That subquery excludes trashed teams, so a user whose team is
            // binned sorts as null alongside the team-less, which is what the
            // cell shows for them too.
            ->orderBy(match ($sortBy) {
                self::ROLE_SORT => 'is_super_admin',
                self::TEAM_SORT => 'team_assignment_name',
                default => $sortBy,
            }, $direction)
            // A stable tie-break, and not optional once a sort can tie: a team
            // name is shared by everyone in it and is_super_admin is 0 for
            // nearly every row, and LIMIT/OFFSET over a non-unique key lets the
            // database order ties differently per page - so page 2 can repeat a
            // row from page 1 or skip one. DocumentController::index and
            // ImageController::index carry the same line.
            //
            // Scout's database engine appends an `id desc` of its own, but that
            // is no substitute: it is skipped the moment any column is declared
            // full-text, and it breaks ties the other way round, which would
            // reorder the team sort for no reason.
            ->orderBy('id');

        $perPage = $request->integer('per_page');

        if ($perPage === self::ALL_PER_PAGE) {
            // Scout's paginate() takes no pre-counted total to hand back, so
            // the single "All" page is built from the result set rather than
            // counted and then fetched again. get() applies no limit, so it is
            // both the page and the count; max() keeps per_page positive when
            // a search matches nothing.
            $results = $builder->get();

            return UserResource::collection(new LengthAwarePaginator(
                $results,
                $results->count(),
                max($results->count(), 1),
                1,
                ['path' => Paginator::resolveCurrentPath(), 'query' => $request->query()],
            ));
        }

        // Null rather than 0 for a missing param - which is what integer()
        // returns - so pagination falls back to the model's per-page.
        return UserResource::collection($builder->paginate($perPage ?: null));
    }

    public function store(StoreUserRequest $request): UserResource
    {
        Gate::authorize('create', User::class);

        $user = new User($request->safe()->only(['name', 'email', 'password']));

        // Direct assignment, not mass assignment: the flag is deliberately kept
        // out of $fillable. Set explicitly rather than left to the column
        // default, or the response would report null — the model carries no
        // such attribute in memory until it is refetched.
        $user->is_super_admin = $request->boolean('is_super_admin');
        $user->save();

        // Shared with the Fortify profile action and update(), so all three
        // agree on what the avatar field means. After save(), not before —
        // media needs a persisted model to attach to.
        $user->applyAvatarInput($request->validated());
        $this->applyTeamInput($request, $user);

        // `password` is fillable and cast `hashed`, so saving hashes it.
        return new UserResource($user);
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        Gate::authorize('update', $user);

        $attributes = $request->safe()->only(['name', 'email']);

        if ($request->has('is_super_admin')) {
            // Same reasoning as destroy(): Gate::before grants a super-admin
            // every ability, so a policy check here would never be reached.
            abort_if($user->is($request->user()), 403);

            $attributes['is_super_admin'] = $request->boolean('is_super_admin');
        }

        // forceFill, not update(): the flag is deliberately not fillable.
        $user->forceFill($attributes)->save();
        $user->applyAvatarInput($request->validated());
        $this->applyTeamInput($request, $user);

        // The media relation is cached on the instance, so the avatar URLs
        // would still describe the pre-upload state without this.
        return new UserResource($user->refresh()->load('roles'));
    }

    /**
     * Apply the membership half of an update, if it asked for one.
     *
     * A super-admin sits above teams per req.txt, so promoting someone clears
     * their team rather than leaving a stale assignment behind. `team_role` is
     * only meaningful alongside a team and defaults to Member.
     */
    protected function applyTeamInput(StoreUserRequest|UpdateUserRequest $request, User $user): void
    {
        if ($user->is_super_admin) {
            $user->assignToTeam(null);

            return;
        }

        if (! $request->has('team_id')) {
            return;
        }

        $teamId = $request->integer('team_id');
        $role = RoleName::tryFrom($request->string('team_role')->toString()) ?? RoleName::Member;

        $user->assignToTeam($teamId === 0 ? null : Team::find($teamId), $role);
    }

    public function destroy(Request $request, User $user): Response
    {
        Gate::authorize('delete', $user);

        // Not in the policy: Gate::before grants super-admins every ability
        // unconditionally, so a policy check here would never be reached.
        abort_if($user->is($request->user()), 403);

        // A soft delete since users joined the rest of req.txt's "every entry
        // should be soft deleted". The avatar deliberately survives it: Media
        // Library's own deleting hook returns early unless the model is being
        // force deleted, so a restore brings the user back whole.
        //
        // Their documents and images stay live and keep their team - only the
        // authorship goes, read as null by the resources. See User's docblock
        // for why there is no cascade here.
        $user->delete();

        return response()->noContent();
    }

    /**
     * Undo a soft delete, from the admin screen's pending-deletion table.
     *
     * Mirrors TeamController::restore(), including the 404 on a live row: the
     * route is bound withTrashed(), so a live user resolves here perfectly well
     * and would otherwise be "restored" to no effect.
     *
     * Their team membership comes back with them without anything happening
     * here - the model_has_roles row was never touched, it was only hidden by
     * User's global scope reaching Team::members().
     */
    public function restore(User $user): UserResource
    {
        Gate::authorize('restore', $user);

        abort_if(! $user->trashed(), 404);

        $user->restore();

        return new UserResource($user->load('media'));
    }
}
