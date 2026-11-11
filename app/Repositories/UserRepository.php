<?php

namespace App\Repositories;

use App\Models\User;

/**
 * Database access for users used by AuthService.
 */
class UserRepository
{
    /**
     * Inserts a user. The password is hashed by the cast on User, so pass it in plain text here.
     */
    public function create(array $data): User
    {
        return User::create($data);
    }

    /**
     * Finds a user by email, including deactivated ones (AuthService decides what to do with them).
     */
    public function findByEmail(string $email): ?User
    {
        return User::where('email', $email)->first();
    }
}
