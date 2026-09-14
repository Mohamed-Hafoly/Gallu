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

    /**
     * Gates the trashed *query*, not an instance — there is no document to check
     * until the rows come back. Mirrors ImagePolicy::viewTrashed: team admins
     * get their own team's trash, since scopeVisibleTo() still narrows it.
     */
    public function viewTrashed(User $user): bool
    {
        return $user->role() === RoleName::Admin;
    }

    /**
     * Same rule as delete: whoever could remove a document can put it back.
     */
    public function restore(User $user, Document $document): bool
    {
        return $this->update($user, $document);
    }

    /**
     * A team-less user shares a team with nobody. The null guard is about the
     * *user*, not the document - documents.team_id is NOT NULL, so the only way
     * `null === null` could pair them off is from this side, where a super-admin
     * or an unassigned member genuinely has no team.
     */
    private function sharesTeamWith(User $user, Document $document): bool
    {
        $teamId = $user->teamAssignment()['team_id'] ?? null;

        return $teamId !== null && $document->team_id === $teamId;
    }
}
