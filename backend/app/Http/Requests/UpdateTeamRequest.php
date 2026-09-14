<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTeamRequest extends FormRequest
{
    /**
     * The unique check omits `withoutTrashed()` — see StoreTeamRequest for why.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:40', Rule::unique('teams', 'name')->ignore($this->route('team'))],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
