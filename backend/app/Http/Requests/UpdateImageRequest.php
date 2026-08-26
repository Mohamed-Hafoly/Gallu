<?php

namespace App\Http\Requests;

use App\Rules\ImageValidationRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Was an ownership abort_if. An admin may now edit a teammate's image,
        // so ImagePolicy::update decides, via Gate::authorize in the controller.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'image' => ImageValidationRules::image(required: false),
            // The document comes from the bound model, not from input: this
            // endpoint accepts no document_id, so an image cannot move between
            // documents here and the scope cannot be steered by the caller.
            'title' => ImageValidationRules::title(
                $this->route('image')->document_id,
                $this->route('image'),
            ),
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
