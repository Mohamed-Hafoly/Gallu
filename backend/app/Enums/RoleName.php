<?php

namespace App\Enums;

/**
 * The application's roles, per req.txt: a global super-admin above teams,
 * each team having admins and members. Doubles as the vocabulary the API
 * reports a user's role in.
 *
 * Only Admin and Member are spatie roles — they are per-team, which is what
 * spatie's teams feature is for, and RoleSeeder seeds just those two. SuperAdmin
 * is NOT assignable through spatie: it is global, so it is backed by the
 * `users.is_super_admin` column and read by Gate::before.
 */
enum RoleName: string
{
    case SuperAdmin = 'super-admin';
    case Admin = 'admin';
    case Member = 'member';
}
