<?php

namespace App\Http\Requests;

use App\Rules\ImageValidationRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreImageRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'image' => ImageValidationRules::image(),
            // Required: every image belongs to exactly one document. Scoped to
            // the caller's team so a forged id is a 422 here rather than a 403
            // from ImagePolicy after the upload has been parsed — a super-admin
            // is exempt, matching Gate::before.
            'document_id' => [
                'required',
                'integer',
                Rule::exists('documents', 'id')
                    ->whereNull('deleted_at')
                    ->when(
                        ! $this->user()->is_super_admin,
                        fn ($rule) => $rule->where('team_id', $this->user()->teamAssignment()['team_id'] ?? null),
                    ),
            ],
            // Scoped to the document, which the sibling rule above validates.
            // A forged or missing id reaches this as 0, matching no rows and
            // passing - the request still fails on document_id itself.
            'title' => ImageValidationRules::title($this->integer('document_id')),
            'description' => ['nullable', 'string', 'max:400'],
            'selected_category_ids' => ['required', 'array', 'min:1'],
            'selected_category_ids.*' => ['integer', Rule::exists('categories', 'id')->whereNull('deleted_at')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ImageValidationRules::messages();
    }
}
