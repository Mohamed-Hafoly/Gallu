<?php

namespace App\Http\Requests;

use App\Rules\TeamMemberValidationRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The whole desired membership of a team, as the manage-members dialog holds it
 * when Save is pressed. Anyone absent from the list is removed, so the key has
 * to be present even when empty.
 */
class SyncTeamMembersRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return TeamMemberValidationRules::members(required: true);
    }
}
