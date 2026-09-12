<?php

use App\Models\TorrentJob;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

test('it clears all torrent jobs', function () {
    makeTorrentJob();
    makeTorrentJob();

    $this->artisan('e2e:reset')->assertSuccessful();

    expect(TorrentJob::count())->toBe(0);
});

test('it ensures a known e2e user and admin exist with a working password', function () {
    $this->artisan('e2e:reset')->assertSuccessful();

    $user = User::where('username', 'e2e-user')->sole();
    $admin = User::where('username', 'e2e-admin')->sole();

    expect(Hash::check('password', $user->password))->toBeTrue()
        ->and($user->isAdmin())->toBeFalse()
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and(Hash::check('password', $admin->password))->toBeTrue()
        ->and($admin->isAdmin())->toBeTrue()
        ->and($admin->hasVerifiedEmail())->toBeTrue();
});

test('running it twice does not duplicate the users', function () {
    $this->artisan('e2e:reset')->assertSuccessful();
    $this->artisan('e2e:reset')->assertSuccessful();

    expect(User::where('username', 'e2e-user')->count())->toBe(1)
        ->and(User::where('username', 'e2e-admin')->count())->toBe(1);
});

test('it clears rate limiter state, so repeated e2e login attempts never lock the suite out', function () {
    $key = 'e2e-user|127.0.0.1';
    RateLimiter::hit($key, 60);
    RateLimiter::hit($key, 60);
    expect(RateLimiter::tooManyAttempts($key, 2))->toBeTrue();

    $this->artisan('e2e:reset')->assertSuccessful();

    expect(RateLimiter::tooManyAttempts($key, 2))->toBeFalse();
});

test('it refuses to run in production', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('e2e:reset')->assertFailed();
});
