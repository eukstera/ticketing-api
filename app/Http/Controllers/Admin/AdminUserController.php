<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAdminResourceRequest;
use App\Http\Requests\UpdateAdminUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin user management. Creation lives in AuthController::storeUser; DELETE is
 * a soft deactivate that also revokes every token of the user. The admin gate
 * is enforced by the can:admin route middleware.
 */
class AdminUserController extends Controller
{
    use ApiResponseTrait;

    public function index(ListAdminResourceRequest $request): JsonResponse
    {
        $query = User::query()->with(['role', 'department', 'designation']);

        if ($request->filled('search')) {
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $request->validated('search')).'%';

            $query->where(function ($search) use ($like) {
                $search->whereRaw("first_name like ? escape '!'", [$like])
                    ->orWhereRaw("last_name like ? escape '!'", [$like])
                    ->orWhereRaw("email like ? escape '!'", [$like]);
            });
        }

        match ($request->validated('status', 'all')) {
            'active' => $query->where('status', true),
            'inactive' => $query->where('status', false),
            default => null,
        };

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->integer('department_id'));
        }

        $paginator = $query->orderBy('id')
            ->paginate($request->integer('per_page', 15))
            ->through(fn (User $user) => (new UserResource($user))->resolve());

        return $this->success('User list retrieved', [
            'result' => ['users' => $paginator->toArray()],
        ]);
    }

    public function show(User $user): JsonResponse
    {
        return $this->success('User retrieved', ['result' => ['user' => $this->present($user)]]);
    }

    public function update(UpdateAdminUserRequest $request, User $user): JsonResponse
    {
        $attributes = $request->validated();

        if (array_key_exists('status', $attributes)) {
            $attributes['status'] = (bool) $attributes['status'];

            if (! $attributes['status'] && $user->status && $this->isCurrentUser($request, $user)) {
                return $this->cannotDeactivateSelf();
            }
        }

        $user->update($attributes);

        if (array_key_exists('status', $attributes) && ! $attributes['status']) {
            $user->tokens()->delete();
        }

        return $this->success('User updated', ['result' => ['user' => $this->present($user)]]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->status && $this->isCurrentUser($request, $user)) {
            return $this->cannotDeactivateSelf();
        }

        $user->update(['status' => false]);
        $user->tokens()->delete();

        return $this->success('User deactivated', ['result' => ['user' => $this->present($user)]]);
    }

    private function isCurrentUser(Request $request, User $user): bool
    {
        return $request->user()->is($user);
    }

    private function cannotDeactivateSelf(): JsonResponse
    {
        return $this->error('You cannot deactivate your own account.', 409);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $user): array
    {
        return (new UserResource($user->refresh()->load(['role', 'department', 'designation'])))->resolve();
    }
}
