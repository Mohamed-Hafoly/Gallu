<?php

namespace App\Policies;

use App\Models\User;

/**
 * Users management is super-admin only
 *
 * Every method denies. That is not a placeholder: `Gate::before` in
 * AppServiceProvider already short-circuits to true for a super-admin, so this
 * policy's job is purely to deny everyone else. Adding a role check here would
 * be dead code — the gate never reaches it for the one role that passes.
 *
 * A super-admin still may not delete themselves; because `Gate::before` wins
 * unconditionally that guard cannot live here, and sits in UserController.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, User $target): bool
    {
        return false;
    }

    public function delete(User $user, User $target): bool
    {
        return false;
    }

    /**
     * Query-level, like DocumentPolicy::viewTrashed() - there is no instance to
     * judge when the caller is only asking to see the bin.
     */
    public function viewTrashed(User $user): bool
    {
        return false;
    }

    public function restore(User $user, User $target): bool
    {
        return false;
    }
}
