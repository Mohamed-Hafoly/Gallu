<?php

namespace App\Http\Requests;

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
            'title' => [
                'required',
                'string',
                'max:140',
                Rule::unique('documents', 'title')
                    ->where('user_id', $this->route('document')->user_id)
                    ->withoutTrashed()
                    ->ignore($this->route('document')),
            ],
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
}
