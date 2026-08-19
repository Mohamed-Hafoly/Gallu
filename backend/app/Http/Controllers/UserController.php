<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexUserRequest;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
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
    public const SORTABLE = ['id', 'name', 'email', 'role', 'created_at', 'updated_at'];

    /**
     * The API's name for the `is_super_admin` column, which is what the table
     * sorts by when the role header is clicked.
     */
    public const ROLE_SORT = 'role';

    /**
     * What the table's "All" option sends for per_page.
     */
    public const ALL_PER_PAGE = -1;

    public function index(IndexUserRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', User::class);

        $search = $request->string('search')->trim()->toString();
        $sortBy = $request->input('sort_by') ?: 'id';
        $direction = $request->input('sort_order') ?: 'asc';

        $query = User::query()
            // The resource's avatar_url / has_avatar read the media relation,
            // which is a query per row without this.
            ->with('media')
            ->when($search !== '', fn ($builder) => $builder->where(
                fn ($grouped) => $grouped
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
            ))
            // `role` is the API's name for the is_super_admin column, so
            // ascending puts plain users (0) before super-admins (1).
            ->orderBy($sortBy === self::ROLE_SORT ? 'is_super_admin' : $sortBy, $direction);

        // No default for the normal path: paginate() falls back to the model's
        // per-page when handed 0, which is what integer() returns for a missing
        // param.
        $perPage = $request->integer('per_page');
        $total = null;

        if ($perPage === self::ALL_PER_PAGE) {
            // Not simply paginate(-1): a negative limit is dropped by the query
            // builder while the offset is still emitted, and `OFFSET` without
            // `LIMIT` is a syntax error in both SQLite and MySQL. Counting once
            // and paginating by that keeps a single page and an honest
            // meta.per_page — the total is handed back so paginate() does not
            // run the same count a second time.
            $total = $query->toBase()->getCountForPagination();
            $perPage = max($total, 1);
        }

        return UserResource::collection($query->paginate($perPage, total: $total));
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

        // The media relation is cached on the instance, so the avatar URLs
        // would still describe the pre-upload state without this.
        return new UserResource($user->refresh()->load('roles'));
    }

    public function destroy(Request $request, User $user): Response
    {
        Gate::authorize('delete', $user);

        // Not in the policy: Gate::before grants super-admins every ability
        // unconditionally, so a policy check here would never be reached.
        abort_if($user->is($request->user()), 403);

        // Media Library cascades the avatar's media rows and files on delete.
        $user->delete();

        return response()->noContent();
    }
}
