<?php

namespace App\Http\Controllers;

use App\Enums\RoleName;
use App\Http\Requests\SyncTeamMembersRequest;
use App\Http\Resources\UserResource;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * A team's membership, super-admin only via TeamPolicy.
 *
 * Kept out of TeamController, which is about the team record itself. No new
 * policy abilities: listing reuses `viewAny` and the write reuses `update`,
 * since changing who is in a team is mutating the team. TeamPolicy's methods
 * all return false and exist only for Gate::before to short-circuit, so an
 * ability of its own would be more dead code.
 *
 * The routes are registered without `withTrashed()`, so a soft-deleted team
 * 404s here — consistent with User::teamAssignment(), which reads a trashed
 * team as no membership at all.
 */
class TeamMemberController extends Controller
{
    public function index(Team $team): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Team::class);

        return UserResource::collection($this->members($team));
    }

    /**
     * Replace the team's membership with the list the dialog holds.
     *
     * A whole-state write rather than one request per add, removal and role
     * change: the dialog edits locally and saves once, so a partial apply would
     * leave the team in a state nobody chose. One transaction, and the fresh
     * membership comes back so the dialog reseeds from the server.
     */
    public function sync(SyncTeamMembersRequest $request, Team $team): AnonymousResourceCollection
    {
        Gate::authorize('update', $team);

        $members = $request->validated('members');

        DB::transaction(function () use ($team, $members) {
            $desired = collect($members)->keyBy('user_id');

            // Anyone dropped from the list. assignToTeam(null) removes them from
            // whatever team they are in, which is safe here because these ids
            // came from this team's own membership.
            $team->members()
                ->whereNotIn('users.id', $desired->keys())
                ->get()
                ->each(fn (User $member) => $member->assignToTeam(null));

            // Re-assigning an unchanged member is a no-op in effect, so there is
            // nothing to diff: assignToTeam() rewrites the single row either way.
            foreach ($desired as $member) {
                User::findOrFail($member['user_id'])
                    ->assignToTeam($team, RoleName::from($member['role']));
            }
        });

        return UserResource::collection($this->members($team));
    }

    /**
     * The team's members, loaded the way the resource needs them.
     *
     * Both eager loads are required or UserResource is a query per row: `media`
     * backs the avatar URLs, and withTeamAssignment() backs `role`/`team`.
     *
     * @return Collection<int, User>
     */
    protected function members(Team $team)
    {
        return $team->members()
            ->with('media')
            ->withTeamAssignment()
            ->orderBy('name')
            ->get();
    }
}
