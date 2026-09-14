<?php

namespace App\Http\Requests;

use App\Rules\ImageValidationRules;
use App\Rules\UserValidationRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The admin edit dialog edits the name, email, avatar and role. The id and both
 * timestamps are rendered disabled, so nothing here accepts them and a submitted
 * one is ignored rather than applied.
 */
class UpdateUserRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => UserValidationRules::name(),
            // The bound user is excluded from the unique check, so saving
            // without touching the address is not a duplicate of itself.
            'email' => UserValidationRules::email($this->route('user')?->id),
            'avatar' => ImageValidationRules::image(required: false),
            'remove_avatar' => ['sometimes', 'boolean'],
            // Promote/demote from the users screen. `sometimes` matters: an
            // update that omits it must leave the flag alone, not clear it.
            'is_super_admin' => ['sometimes', 'boolean'],
            // Membership. Null clears it; the role only applies when a team is
            // given, and is ignored for a super-admin, who sits above teams.
            'team_id' => ['sometimes', 'nullable', 'integer', 'exists:teams,id'],
            'team_role' => ['sometimes', 'nullable', 'string', 'in:admin,member'],
        ];
    }
}
