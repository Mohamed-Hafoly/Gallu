<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Rules\ImageValidationRules;
use App\Rules\UserValidationRules;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /**
     * Validate and update the given user's profile information.
     *
     * The SPA sends this as a multipart POST spoofing PUT, so `$input` may
     * carry an `avatar` UploadedFile alongside the plain fields.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'name' => UserValidationRules::name(),
            'email' => UserValidationRules::email($user->id),
            'avatar' => ImageValidationRules::image(required: false),
            'remove_avatar' => ['sometimes', 'boolean'],
        ])->validateWithBag('updateProfileInformation');

        if (
            $input['email'] !== $user->email &&
            $user instanceof MustVerifyEmail
        ) {
            $this->updateVerifiedUser($user, $input);
        } else {
            $user->forceFill([
                'name' => $input['name'],
                'email' => $input['email'],
            ])->save();
        }

        $this->updateAvatar($user, $input);
    }

    /**
     * Apply the avatar half of the request, if it asked for one.
     *
     * Clearing the collection is enough to "remove" an avatar — the collection
     * falls back to the default image. The collection is `singleFile()`, so a
     * new upload replaces the old one without an explicit clear.
     *
     * @param  array<string, mixed>  $input
     */
    protected function updateAvatar(User $user, array $input): void
    {
        if (filter_var($input['remove_avatar'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $user->clearMediaCollection(User::AVATAR_COLLECTION);

            return;
        }

        if (! isset($input['avatar'])) {
            return;
        }

        $user->setAvatarFromFile($input['avatar']);
    }

    /**
     * Update the given verified user's profile information.
     *
     * @param  array<string, string>  $input
     */
    protected function updateVerifiedUser(User $user, array $input): void
    {
        $user->forceFill([
            'name' => $input['name'],
            'email' => $input['email'],
            'email_verified_at' => null,
        ])->save();

        $user->sendEmailVerificationNotification();
    }
}
