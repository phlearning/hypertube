<?php

namespace App\Console\Commands\E2e;

use App\Enums\UserRole;
use App\Models\TorrentJob;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Test-support only: gives the Playwright e2e suite a clean, known slate to
 * seed against, independent of whatever real download activity happened in
 * this environment. Never runs in production.
 */
#[Signature('e2e:reset')]
#[Description('Reset test data for the Playwright e2e suite (clears torrent_jobs, ensures known test users exist).')]
class ResetCommand extends Command
{
    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to run e2e:reset in production.');

            return self::FAILURE;
        }

        // Also clears Fortify's login rate limiter (5/min per username+IP —
        // FortifyServiceProvider::configureRateLimiting): the suite logs
        // e2e-user/e2e-admin in once per run in global-setup.ts, and running
        // it repeatedly while iterating on the suite itself blows through
        // that limit in well under a minute.
        Cache::flush();

        TorrentJob::query()->delete();

        $this->ensureUser('e2e-user', 'E2E', 'User', UserRole::User);
        $this->ensureUser('e2e-admin', 'E2E', 'Admin', UserRole::Admin);

        $this->info('e2e test data reset.');

        return self::SUCCESS;
    }

    /**
     * `role` and `email_verified_at` are deliberately outside User::$fillable
     * (see User::isAdmin()'s docblock), so they can't be set via
     * updateOrCreate()'s mass assignment — set directly instead. The e2e
     * suite needs a pre-verified account: every app route sits behind the
     * `verified` middleware, and there's no email inbox to click a link from
     * in a test run.
     */
    private function ensureUser(string $username, string $firstname, string $lastname, UserRole $role): void
    {
        $user = User::query()->updateOrCreate(
            ['username' => $username],
            [
                'firstname' => $firstname,
                'lastname' => $lastname,
                'email' => "{$username}@example.test",
                'password' => 'password',
            ]
        );

        $user->role = $role;
        // App\Providers\AppServiceProvider makes the global now() helper
        // return CarbonImmutable, but User::$email_verified_at is typed as
        // (mutable) Carbon — spelled out here to match the property exactly.
        $user->email_verified_at = Carbon::now();
        $user->save();
    }
}
