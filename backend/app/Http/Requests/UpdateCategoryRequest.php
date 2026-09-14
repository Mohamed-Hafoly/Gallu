<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCategoryRequest extends FormRequest
{
    /**
     * Either name may be sent, but at least one must be.
     *
     * `required_without` is an implicit rule, so it still runs on an absent
     * field while the remaining rules are skipped — that is what makes a
     * partial update work. Adding `sometimes` here would suppress it.
     *
     * The unique checks deliberately omit `withoutTrashed()`: the table carries
     * real unique indexes on both name columns and those ignore `deleted_at`,
     * so excluding trashed rows here would let validation pass and the write
     * then fail on the index. A trashed name is freed by restoring its category.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $category = $this->route('category');

        return [
            'name_en' => ['required_without:name_ar', 'string', 'max:40', Rule::unique('categories', 'name_en')->ignore($category)],
            'name_ar' => ['required_without:name_en', 'string', 'max:40', Rule::unique('categories', 'name_ar')->ignore($category)],
        ];
    }
}
