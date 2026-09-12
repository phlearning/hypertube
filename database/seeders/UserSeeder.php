<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // The dev-seeded account doubles as the local admin account — there's
        // no admin-invite flow yet, so this is the only way to get one.
        User::factory()->create([
            'username' => 'fxc-ai',
            'firstname' => 'François-Xavier',
            'lastname' => 'Condreau',
            'email' => 'nimportequoi@gmail.com',
            'password' => 'password',
            'role' => UserRole::Admin,
        ]);

        User::factory(100)->create();
    }
}
