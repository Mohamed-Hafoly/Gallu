<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
{
    /**
     * The unique checks deliberately omit `withoutTrashed()` — see
     * UpdateCategoryRequest for why.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name_en' => ['required', 'string', 'max:40', Rule::unique('categories', 'name_en')],
            'name_ar' => ['required', 'string', 'max:40', Rule::unique('categories', 'name_ar')],
        ];
    }
}
