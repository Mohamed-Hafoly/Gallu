<?php

namespace App\Rules;

use Illuminate\Validation\Rule;

/**
 * The membership half of a team payload, shared by the create endpoint and the
 * sync endpoint so the two can never drift — same idea as UserValidationRules.
 */
class TeamMemberValidationRules
{
    /**
     * @param  bool  $required  Sync must state the whole desired membership, so
     *                          the key has to be present; create may omit it.
     *                          `present`, not `required`, because an empty array
     *                          is meaningful — it empties the team — and
     *                          `required` rejects one.
     * @return array<string, mixed>
     */
    public static function members(bool $required = false): array
    {
        return [
            'members' => $required ? ['present', 'array'] : ['sometimes', 'array'],
            'members.*.user_id' => [
                'required',
                'integer',
                'distinct',
                // A super-admin sits above teams and is never a valid member.
                // Checked here rather than in the controller so the request is
                // rejected before anything is written.
                //
                // 0, not false: PDO binds a PHP false as an empty string, which
                // matches no row on SQLite — where the tests run, unlike dev.
                Rule::exists('users', 'id')->where('is_super_admin', 0),
            ],
            // Spelled out rather than Rule::enum(RoleName::class) so the global
            // `super-admin` cannot be smuggled in — same as team_role on the
            // user requests.
            'members.*.role' => ['required', 'string', 'in:admin,member'],
        ];
    }
}
