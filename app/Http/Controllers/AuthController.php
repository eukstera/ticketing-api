<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\StoreAdminUserRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private AuthService $authService) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->login($request->only('email', 'password'));

        if (! $result) {
            return $this->error('Credentials not found', 401);
        }

        $result['user']->load(['role', 'department', 'designation']);

        return $this->success('User logged-in successfully', [
            'result' => [
                'user' => new UserResource($result['user']),
                'token' => $result['token'],
                'expires_at' => $result['expires_at'],
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->success('Logged out successfully', ['result' => (object) []]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['role', 'department', 'designation']);

        return $this->success('User retrieved successfully', [
            'result' => ['user' => new UserResource($user)],
        ]);
    }

    public function storeUser(StoreAdminUserRequest $request): JsonResponse
    {
        $user = $this->authService->createUser($request->validated());
        $user->load(['role', 'department', 'designation']);

        return $this->success('User created successfully', [
            'result' => ['user' => new UserResource($user)],
        ], 201);
    }
}
