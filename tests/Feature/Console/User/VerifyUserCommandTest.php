<?php

use App\Models\User;

test('it verifies a user account', function () {
    $user = User::factory()->unverified()->create([
        'username' => 'john-doe',
    ]);

    $this->artisan('user:verify', ['identifier' => 'john-doe'])
        ->assertSuccessful();

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('it accepts an email address as identifier', function () {
    $user = User::factory()->unverified()->create([
        'username' => 'jane-doe',
        'email' => 'jane@example.test',
    ]);

    $this->artisan('user:verify', ['identifier' => 'jane@example.test'])
        ->assertSuccessful();

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('it fails when the user does not exist', function () {
    $this->artisan('user:verify', ['identifier' => 'missing'])
        ->assertFailed();
});
