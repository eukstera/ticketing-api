<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\UserRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\NewAccessToken;

class AuthService
{
    public function __construct(
        private UserRepository $userRepository
    ) {}

    /**
     * Create an account; the password is hashed by the User model cast.
     *
     * @param  array<string, mixed>  $data
     */
    public function createUser(array $data): User
    {
        return $this->userRepository->create($data);
    }

    /**
     * Verify the credentials without touching the session guard and issue a token.
     *
     * @param  array{email: string, password: string}  $credentials
     * @return array{user: User, token: string, expires_at: ?string}|null
     *
     * @throws AuthorizationException when the account is deactivated
     */
    public function login(array $credentials): ?array
    {
        $user = $this->userRepository->findByEmail($credentials['email']);

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return null;
        }

        if (! $user->status) {
            throw new AuthorizationException('This account has been deactivated.');
        }

        $newToken = $this->issueToken($user);

        return [
            'user' => $user,
            'token' => $newToken->plainTextToken,
            'expires_at' => $newToken->accessToken->expires_at?->toIso8601String(),
        ];
    }

    private function issueToken(User $user): NewAccessToken
    {
        return $user->createToken('auth_token', ['*'], now()->addMinutes((int) config('sanctum.expiration')));
    }
}
