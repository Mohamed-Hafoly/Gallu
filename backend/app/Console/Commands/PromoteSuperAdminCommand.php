<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class PromoteSuperAdminCommand extends Command
{
    protected $signature = 'app:promote-super-admin {email : The email of an existing user}';

    protected $description = 'Grant the super-admin role to an existing user';

    /**
     * Bootstraps the *first* super admin, which no one can grant through the
     * app because no super admin exists yet. Later promotions belong to the
     * users management endpoints.
     */
    public function handle(): int
    {
        $email = $this->argument('email');
        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("No user found with the email [{$email}].");

            return self::FAILURE;
        }

        // forceFill, not update(): the flag is deliberately not fillable, so
        // registration and the profile update cannot smuggle it in.
        $user->forceFill(['is_super_admin' => true])->save();

        $this->info("[{$email}] is now a super admin.");

        return self::SUCCESS;
    }
}
