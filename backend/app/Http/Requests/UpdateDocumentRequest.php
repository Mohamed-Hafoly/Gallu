<?php

namespace App\Http\Requests;

use App\Rules\DocumentValidationRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // DocumentPolicy::update, via Gate::authorize in the controller, is the
        // real check — an admin may edit a teammate's document, so ownership is
        // deliberately not asserted here.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Scoped to the *incoming* team, not the stored one: team_id is
            // editable here, so scoping to where the document currently sits
            // would let it be moved into a team that already holds that title.
            'title' => DocumentValidationRules::title(
                $this->integer('team_id'),
                $this->route('document'),
            ),
            'description' => ['nullable', 'string', 'max:400'],
            // Editable, like on create: the edit dialog offers the same picker.
            // The controller is what stops a non-super-admin moving a document
            // out of their own team. Trashed teams are excluded, since
            // /api/teams/picker does not offer them either.
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
