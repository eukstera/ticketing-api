<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The signed-in user's own profile: PATCH /api/me (name) and PATCH /api/me/password. Rate limited by
 * the "profile" limiter.
 */
class ProfileController extends Controller
{
    use ApiResponseTrait;

    /**
     * PATCH /api/me. Changes your own first or last name.
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update($request->validated());

        return $this->success('Profile updated', [
            'result' => ['user' => new UserResource($user->load(['role', 'department', 'designation']))],
        ]);
    }

    /**
     * PATCH /api/me/password. Changes your own password after checking the current one.
     */
    public function updatePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update(['password' => $request->validated('password')]);

        $currentToken = $user->currentAccessToken();
        $user->tokens()
            ->when($currentToken instanceof PersonalAccessToken, fn ($tokens) => $tokens->where('id', '!=', $currentToken->id))
            ->delete();

        return $this->success('Password updated', ['result' => (object) []]);
    }
}
