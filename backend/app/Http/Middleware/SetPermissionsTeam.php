<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Set spatie's ambient team from the authenticated user, so `hasRole()`,
 * `can()` and the role middleware answer for the team that user is actually in.
 *
 * One team per user, so the team is derived rather than picked — there is no
 * "current team" on the session for this to read.
 *
 * Registered inside the `auth:sanctum` group rather than prepended to the api
 * stack: that stack runs SetLocaleFromHeader, then EnsureFrontendRequestsAreStateful,
 * then SubstituteBindings, and prepending would land before the session starts,
 * leaving `$request->user()` null on every request.
 *
 * That does depart from spatie's "register before SubstituteBindings" note. It
 * is safe here because that advice protects route-model bindings whose queries
 * are team-scoped, and none are — {team}, {user}, {category} and {image} are all
 * global lookups.
 */
class SetPermissionsTeam
{
    public function handle(Request $request, Closure $next): Response
    {
        // Null, not a sentinel, for a user with no team: it matches no pivot row
        // on reads, and makes an assignRole() without a team fail loudly against
        // the NOT NULL column rather than writing an orphan.
        setPermissionsTeamId($request->user()?->teamAssignment()['team_id'] ?? null);

        return $next($request);
    }
}
