<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Rules\ImageValidationRules;
use App\Rules\UserValidationRules;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * The SPA posts this as multipart, so `$input` may carry an optional
     * `avatar` UploadedFile alongside the plain fields.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            'name' => UserValidationRules::name(),
            'email' => UserValidationRules::email(),
            'password' => $this->passwordRules(),
            'avatar' => ImageValidationRules::image(required: false),
        ])->validate();

        $user = User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => Hash::make($input['password']),
        ]);

        if (isset($input['avatar'])) {
            $user->setAvatarFromFile($input['avatar']);
        }

        return $user;
    }
}
