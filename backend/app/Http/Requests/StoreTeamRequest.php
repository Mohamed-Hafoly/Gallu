<?php

namespace App\Http\Requests;

use App\Rules\TeamMemberValidationRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTeamRequest extends FormRequest
{
    /**
     * The unique check deliberately omits `withoutTrashed()`: the table carries
     * a real unique index on `name` and that ignores `deleted_at`, so excluding
     * trashed rows here would let validation pass and the write then fail on
     * the index. A trashed name is freed by restoring its team — same reasoning
     * as StoreCategoryRequest.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:40', Rule::unique('teams', 'name')],
            'description' => ['nullable', 'string', 'max:255'],
            // Optional starting membership, picked in the create dialog. Shared
            // with the sync endpoint so the two cannot drift.
            ...TeamMemberValidationRules::members(),
        ];
    }
}
