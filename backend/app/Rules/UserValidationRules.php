<?php

// app/Rules/UserValidationRules.php
namespace App\Rules;

use App\Actions\Fortify\PasswordValidationRules;
use Illuminate\Validation\Rule;

class UserValidationRules
{
    public static function name(): array
    {
        return ['required', 'min:4', 'string', 'max:255'];
    }

    public static function email(?int $ignoreUserId = null): array
    {
        return [
            'required',
            'string',
            'email',
            'max:255',
            Rule::unique('users')->ignore($ignoreUserId),
        ];
    }
}
