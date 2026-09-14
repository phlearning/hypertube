<?php

namespace App\Console\Commands\User;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('user:verify {identifier : Username or email of the user to verify}')]
#[Description('Mark a user account as email-verified.')]
class VerifyUserCommand extends Command
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

        $user->markEmailAsVerified();
        $user->save();

        $this->info("{$user->username} is now verified.");

        return self::SUCCESS;
    }
}
