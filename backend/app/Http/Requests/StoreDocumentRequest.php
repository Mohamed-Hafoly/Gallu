<?php

namespace App\Http\Requests;

use App\Rules\DocumentValidationRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The controller's Gate::authorize('create', Document::class) is the
        // real check; kept permissive here so a member gets a 403 rather than
        // this request's generic denial.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Scoped to the destination team, which the sibling rule below
            // validates. A forged or missing id reaches this as 0, which scopes
            // the check to nothing - the request still fails on team_id.
            'title' => DocumentValidationRules::title($this->integer('team_id')),
            'description' => ['nullable', 'string', 'max:400'],
            // Chosen in the create dialog rather than derived from the session,
            // so a super-admin - who belongs to no team - can still file a
            // document under one. The controller is what stops everyone else
            // naming a team that is not their own. Trashed teams are excluded:
            // a soft-deleted team is not offered by /api/teams/picker either.
            'team_id' => [
                'required',
                'integer',
                Rule::exists('teams', 'id')->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return DocumentValidationRules::messages();
    }
}
