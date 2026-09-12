<?php

use App\Enums\UserRole;
use App\Models\User;

test('a user defaults to the user role and is not admin', function () {
    $user = User::factory()->create();

    expect($user->role)->toBe(UserRole::User)
        ->and($user->isAdmin())->toBeFalse();
});

test('a user with the admin role is admin', function () {
    $user = User::factory()->create(['role' => UserRole::Admin]);

    expect($user->isAdmin())->toBeTrue();
});

test('role cannot be mass-assigned', function () {
    $user = User::create([
        'username' => 'someone',
        'email' => 'someone@example.com',
        'firstname' => 'Some',
        'lastname' => 'One',
        'password' => 'password',
        'role' => UserRole::Admin,
    ]);

    expect($user->role)->toBe(UserRole::User);
});
