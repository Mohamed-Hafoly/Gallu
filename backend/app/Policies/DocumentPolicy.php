<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\Document;
use App\Models\User;

/**
 * Admins own the document lifecycle within their team; members only read.
 *
 * Unlike TeamPolicy and UserPolicy — whose methods all `return false` because
 * Gate::before has already let the only permitted role through — these methods
 * carry real logic: admins and members have genuinely different rights here, and
 * both are below super-admin. No method needs a super-admin branch, because
 * Gate::before still short-circuits before any of this runs.
 */
class DocumentPolicy
{
    /** Everyone signed in may list; scopeVisibleTo() is what narrows the rows. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Document $document): bool
    {
        return $this->sharesTeamWith($user, $document);
    }

    public function create(User $user): bool
    {
        return $user->role() === RoleName::Admin;
    }

    public function update(User $user, Document $document): bool
    {
        return $user->role() === RoleName::Admin
            && $this->sharesTeamWith($user, $document);
    }

    public function delete(User $user, Document $document): bool
    {
        return $this->update($user, $document);
    }


    public function restore(User $user, Document $document): bool
    {
        return false;
    }

    /**
     * A team-less user shares a team with nobody: `null === null` would
     * otherwise make every team-less user a peer of every team-less document.
     */
    private function sharesTeamWith(User $user, Document $document): bool
    {
        $teamId = $user->teamAssignment()['team_id'] ?? null;

        return $teamId !== null && $document->team_id === $teamId;
    }
}
