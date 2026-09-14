<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;

/**
 * Managing categories is super-admin only, per req.txt.
 *
 * Every method denies, exactly as TeamPolicy and UserPolicy do: `Gate::before`
 * in AppServiceProvider already short-circuits to true for a super-admin, so
 * this policy's only job is to deny everyone else. A role check here would be
 * dead code — the gate never reaches it for the one role that passes.
 *
 * Note what is *not* here: the picker. Every user who uploads an image needs
 * the category list to tag it with, so `CategoryController::picker()` is
 * deliberately left ungated and has no ability of its own. `viewAny` covers the
 * admin listing only, which is a different endpoint and includes trashed rows.
 */
class CategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Category $category): bool
    {
        return false;
    }

    public function delete(User $user, Category $category): bool
    {
        return false;
    }

    public function restore(User $user, Category $category): bool
    {
        return false;
    }
}
