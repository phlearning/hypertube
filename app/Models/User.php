<?php

namespace App\Models;

use App\Enums\Languages;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $username
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property UserRole $role
 */
#[Fillable(['username', 'email', 'firstname', 'lastname', 'password', 'preferredlanguage', 'profilepicture'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Mirrors the `role` column's database default in-memory: without this,
     * a model built via `create()`/`factory()` reads `role` as null until
     * reloaded from the database, even though the row itself defaults to
     * 'user' — a gap that would make `isAdmin()` unreliable right after
     * registration, in the very same request.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => 'user',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'preferredlanguage' => Languages::class,
            'role' => UserRole::class,
        ];
    }

    public function socialAccounts()
    {
        return $this->hasMany(SocialAccount::class);
    }

    /**
     * `role` is deliberately left out of #[Fillable] above: it must only
     * ever be set via a direct property assignment (seeder, tinker, a
     * future admin-management feature) — never through mass assignment on
     * a form a regular user could reach.
     */
    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }
}
