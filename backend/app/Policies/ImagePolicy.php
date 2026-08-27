<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\Document;
use App\Models\Image;
use App\Models\User;

/**
 * Members contribute images to their team's documents and may edit only their
 * own; admins may edit any image in their team.
 *
 * Like DocumentPolicy and unlike TeamPolicy, these methods carry real logic:
 * Gate::before has already granted super-admins everything, so what remains is
 * the genuine admin/member distinction.
 */
class ImagePolicy
{
    /** Everyone signed in may list; scopeVisibleTo() is what narrows the rows. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Image $image): bool
    {
        return $this->teamOf($user) !== null
            && $image->document->team_id === $this->teamOf($user);
    }

    /**
     * Creation is checked against the target document rather than the image,
     * which does not exist yet. StoreImageRequest validates the same rule so a
     * forged document_id fails as a 422 before the upload is even processed.
     */
    public function create(User $user, Document $document): bool
    {
        $teamId = $this->teamOf($user);

        return $teamId !== null && $document->team_id === $teamId;
    }

    public function update(User $user, Image $image): bool
    {
        if ($image->user_id === $user->id) {
            return true;
        }

        // Not the owner, so this is only allowed as the team's admin.
        return $user->role() === RoleName::Admin
            && $this->teamOf($user) !== null
            && $image->document->team_id === $this->teamOf($user);
    }

    public function delete(User $user, Image $image): bool
    {
        return $this->update($user, $image);
    }

    /**
     * Whether the images listing may include soft-deleted rows.
     *
     * True for everyone signed in, like viewAny above and for the same reason:
     * everybody has a trash, and *whose* rows are in it is a scoping question
     * rather than an authorisation one. ImageController::index narrows it —
     * see viewAllTrashed.
     *
     * Not tied to an instance, because it gates the *query*: there is no image
     * to check until the rows come back.
     */
    public function viewTrashed(User $user): bool
    {
        return true;
    }

    /**
     * Whether that trash is the whole team's, or only the caller's own images.
     *
     * A member may reach their own deleted images but not a teammate's; an
     * admin gets the team's (scopeVisibleTo still bounds it to that team).
     *
     * Lives here rather than as an inline is_super_admin check in the
     * controller precisely so Gate::before applies: a super-admin is not an
     * Admin by role(), and would fail the comparison below.
     */
    public function viewAllTrashed(User $user): bool
    {
        return $user->role() === RoleName::Admin;
    }

    /**
     * Same rule as delete: whoever could remove an image can put it back.
     */
    public function restore(User $user, Image $image): bool
    {
        return $this->update($user, $image);
    }

    private function teamOf(User $user): ?int
    {
        return $user->teamAssignment()['team_id'] ?? null;
    }
}
