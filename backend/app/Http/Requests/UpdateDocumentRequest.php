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
        ];
    }
}
