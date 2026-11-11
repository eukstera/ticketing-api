<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\StoreAdminUserRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Login, logout, current user, and account creation by an admin (POST /api/admin/users). Login returns
 * a Sanctum bearer token that expires after config sanctum.expiration minutes (7 days).
 */
class AuthController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private AuthService $authService) {}

    /**
     * POST /api/login (public, 5 per minute per email and IP). 401 for a wrong email or password, 403 for
     * a deactivated account, otherwise the user and a bearer token.
     */
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

    /**
     * POST /api/logout. Deletes the token that made the request.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->success('Logged out successfully', ['result' => (object) []]);
    }

    /**
     * GET /api/me. The signed-in user with role, department and designation. Roles carry a key; the front
     * end checks the key, not the name.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['role', 'department', 'designation']);

        return $this->success('User retrieved successfully', [
            'result' => ['user' => new UserResource($user)],
        ]);
    }

    /**
     * POST /api/admin/users (admin only). The only way accounts are created: an admin sets the email and
     * the first password. There is no self-registration.
     */
    public function storeUser(StoreAdminUserRequest $request): JsonResponse
    {
        $user = $this->authService->createUser($request->validated());
        $user->load(['role', 'department', 'designation']);

        return $this->success('User created successfully', [
            'result' => ['user' => new UserResource($user)],
        ], 201);
    }
}
