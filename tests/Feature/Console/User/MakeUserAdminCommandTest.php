<?php

use App\Models\User;

test('it promotes a user to admin and verifies the account', function () {
    $user = User::factory()->unverified()->create([
        'username' => 'john-doe',
    ]);

    $this->artisan('user:make-admin', ['identifier' => 'john-doe'])
        ->assertSuccessful();

    expect($user->fresh()->isAdmin())->toBeTrue()
        ->and($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('it accepts an email address as identifier', function () {
    $user = User::factory()->unverified()->create([
        'username' => 'jane-doe',
        'email' => 'jane@example.test',
    ]);

    $this->artisan('user:make-admin', ['identifier' => 'jane@example.test'])
        ->assertSuccessful();

    expect($user->fresh()->isAdmin())->toBeTrue();
});

test('it fails when the user does not exist', function () {
    $this->artisan('user:make-admin', ['identifier' => 'missing'])
        ->assertFailed();
});
