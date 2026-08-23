<?php

namespace App\Http\Requests;

use App\Actions\Fortify\PasswordValidationRules;
use App\Rules\ImageValidationRules;
use App\Rules\UserValidationRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The admin create dialog: name, email, password, role and an optional avatar.
 *
 * Multipart so the avatar can ride along, but without the update's `_method`
 * spoofing — this route is already POST.
 */
class StoreUserRequest extends FormRequest
{
    // The same rules registration uses, so an admin-created password and a
    // self-registered one can never drift apart. Includes `confirmed`, hence
    // the dialog's second password field.
    use PasswordValidationRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => UserValidationRules::name(),
            // No ignore id here, unlike the update — there is no existing row
            // for the address to belong to yet.
            'email' => UserValidationRules::email(),
            'password' => $this->passwordRules(),
            'is_super_admin' => ['sometimes', 'boolean'],
            // Membership, same shape as the update — so the create and edit
            // dialogs can offer the same controls.
            'team_id' => ['sometimes', 'nullable', 'integer', 'exists:teams,id'],
            'team_role' => ['sometimes', 'nullable', 'string', 'in:admin,member'],
            // Optional, exactly as on registration — a user without one falls
            // back to the shipped default image.
            'avatar' => ImageValidationRules::image(required: false),
        ];
    }
}
