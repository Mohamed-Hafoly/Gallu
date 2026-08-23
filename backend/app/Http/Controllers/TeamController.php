<?php

namespace App\Http\Controllers;

use App\Enums\RoleName;
use App\Http\Requests\StoreTeamRequest;
use App\Http\Requests\UpdateTeamRequest;
use App\Http\Resources\TeamResource;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Teams management, super-admin only via TeamPolicy.
 *
 * Follows CategoryController rather than UserController: teams are few, so one
 * unpaginated index returns live and trashed rows together and the screen
 * filters client-side.
 */
class TeamController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Team::class);

        return TeamResource::collection(
            Team::withTrashed()->with('user')->withCount('members')->orderBy('id')->get()
        );
    }

    /**
     * Live teams only, for the user dialog's team select.
     */
    public function picker(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Team::class);

        return TeamResource::collection(Team::orderBy('name')->get());
    }

    /**
     * Create a team, optionally with its starting membership.
     *
     * The whole thing is one transaction: a team that kept only some of the
     * members it was created with would be worse than no team at all, and the
     * caller has no way to tell which half landed.
     */
    public function store(StoreTeamRequest $request): TeamResource
    {
        Gate::authorize('create', Team::class);

        $team = DB::transaction(function () use ($request) {
            $team = Team::create([
                ...$request->safe()->only(['name', 'description']),
                'user_id' => $request->user()->id,
            ]);

            foreach ($request->validated('members', []) as $member) {
                // assignToTeam(), never assignRole(): it is the only writer of
                // membership, and it is what keeps one team per user. A member
                // who already belonged elsewhere is moved here.
                User::findOrFail($member['user_id'])
                    ->assignToTeam($team, RoleName::from($member['role']));
            }

            return $team;
        });

        return new TeamResource($team->load('user')->loadCount('members'));
    }

    public function update(UpdateTeamRequest $request, Team $team): TeamResource
    {
        Gate::authorize('update', $team);

        $team->update($request->safe()->only(['name', 'description']));

        return new TeamResource($team->load('user')->loadCount('members'));
    }

    /**
     * Soft delete. Members keep their assignment rows so a restore brings the
     * team back intact — User::teamAssignment() excludes trashed teams, so they
     * read as team-less in the meantime.
     */
    public function destroy(Team $team): Response
    {
        Gate::authorize('delete', $team);

        $team->delete();

        return response()->noContent();
    }

    public function restore(Team $team): TeamResource
    {
        Gate::authorize('restore', $team);

        abort_if(! $team->trashed(), 404);

        $team->restore();

        return new TeamResource($team->load('user')->loadCount('members'));
    }
}
