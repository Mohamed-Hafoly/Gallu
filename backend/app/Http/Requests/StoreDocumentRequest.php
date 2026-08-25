<?php

namespace App\Http\Requests;

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
            'title' => [
                'required',
                'string',
                'max:140',
                Rule::unique('documents', 'title')->where('user_id', $this->user()->id)->withoutTrashed(),
            ],
            'description' => ['nullable', 'string', 'max:400'],
        ];
    }
}
