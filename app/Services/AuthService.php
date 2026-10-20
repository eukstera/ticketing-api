<?php

namespace App\Services;

use App\Repositories\UserRepository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    public function __construct(
        private UserRepository $userRepository
    ) {}

    public function register(array $data): array
    {
        $data['password'] = Hash::make($data['password']);
        $user  = $this->userRepository->create($data);
        $token = $user->createToken('auth_token')->plainTextToken;

        return [
            'user'  => $user,
            'token' => $token
        ];
    }

    public function login(array $data): ? array
    {
        if (!Auth::attempt($data)) {
            return null;
        }

        // $user = $this->userRepository->findByEmail($data['email']);
        $user = Auth::user();
        $token = $user->createToken('auth_token')->plainTextToken;

        return [
            'user' => $user,
            'token' => $token
        ];

    }

}
