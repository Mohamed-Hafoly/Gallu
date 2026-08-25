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
            'title' => [
                'required',
                'string',
                'max:140',
                // Scoped to the image's owner, not the caller: an admin editing
                // a teammate's image must not collide with their own titles.
                Rule::unique('images', 'title')
                    ->where('user_id', $this->route('image')->user_id)
                    ->withoutTrashed()
                    ->ignore($this->route('image')),
            ],
            'description' => ['nullable', 'string', 'max:400'],
            'selected_category_ids' => ['required', 'array', 'min:1'],
            'selected_category_ids.*' => ['integer', Rule::exists('categories', 'id')->whereNull('deleted_at')],
        ];
    }
}
