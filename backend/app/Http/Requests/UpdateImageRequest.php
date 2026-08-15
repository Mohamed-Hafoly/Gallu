<?php

namespace App\Http\Requests;

use App\Rules\ImageValidationRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_if($this->route('image')->user_id !== $this->user()->id, 404);

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
                Rule::unique('images', 'title')
                    ->where('user_id', $this->user()->id)
                    ->withoutTrashed()
                    ->ignore($this->route('image')),
            ],
            'description' => ['nullable', 'string', 'max:400'],
            'selected_category_ids' => ['required', 'array', 'min:1'],
            'selected_category_ids.*' => ['integer', Rule::exists('categories', 'id')->whereNull('deleted_at')],
        ];
    }
}
