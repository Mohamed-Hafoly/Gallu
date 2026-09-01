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
                        // 0 for a team-less caller, not null, and the fallback
                        // must stay *inside* the constraint rather than skipping
                        // it: dropping the where for a team-less user would let
                        // them file an image under any live document in the app.
                        // No team has id 0, so this matches nothing - the same
                        // sentinel ImageValidationRules::title() uses one line
                        // below for a missing document_id.
                        fn ($rule) => $rule->where('team_id', $this->user()->teamAssignment()['team_id'] ?? 0),
                    ),
            ],
            // Scoped to the document, which the sibling rule above validates.
            // A forged or missing id reaches this as 0, matching no rows and
            // passing - the request still fails on document_id itself.
            'title' => ImageValidationRules::title($this->integer('document_id')),
            'description' => ['nullable', 'string', 'max:400'],
            ...ImageValidationRules::categories(),
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
