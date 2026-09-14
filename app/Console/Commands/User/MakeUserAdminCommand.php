<?php

namespace App\Console\Commands\User;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('user:make-admin {identifier : Username or email of the user to promote}')]
#[Description('Promote a user to the admin role and keep the account verified.')]
class MakeUserAdminCommand extends Command
{
    public function handle(): int
    {
        $identifier = (string) $this->argument('identifier');

        $user = User::query()
            ->where('username', $identifier)
            ->orWhere('email', $identifier)
            ->first();

        if ($user === null) {
            $this->error("User not found: {$identifier}");

            return self::FAILURE;
        }

        $user->role = UserRole::Admin;
        $user->markEmailAsVerified();
        $user->save();

        $this->info("{$user->username} is now an admin and verified.");

        return self::SUCCESS;
    }
}
