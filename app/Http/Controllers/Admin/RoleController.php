<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\RoleRequest;
use App\Http\Resources\Admin\RoleResource;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;

/**
 * Admin CRUD for roles. The built-in Section Head role is found by its key (Role::DEPARTMENT_HEAD),
 * not its name: it cannot be renamed or deactivated. A role still held by active users cannot be
 * deactivated.
 */
class RoleController extends AdminResourceController
{
    protected function modelClass(): string
    {
        return Role::class;
    }

    protected function requestClass(): string
    {
        return RoleRequest::class;
    }

    protected function resourceClass(): string
    {
        return RoleResource::class;
    }

    protected function listKey(): string
    {
        return 'roles';
    }

    protected function itemKey(): string
    {
        return 'role';
    }

    protected function noun(): string
    {
        return 'Role';
    }

    protected function searchColumn(): string
    {
        return 'role';
    }

    protected function columnMap(): array
    {
        return ['name' => 'role'];
    }

    /**
     * @param  Role  $row
     */
    protected function guardUpdate(Model $row, array $attributes): ?JsonResponse
    {
        if ($this->isProtected($row) && ($attributes['role'] ?? $row->role) !== $row->role) {
            return $this->error('The Section Head role cannot be renamed.', 409);
        }

        return null;
    }

    /**
     * @param  Role  $row
     */
    protected function guardDeactivate(Model $row): ?JsonResponse
    {
        if ($this->isProtected($row)) {
            return $this->error('The Section Head role cannot be deactivated.', 409);
        }

        $holders = User::query()->where('role_id', $row->id)->where('status', true)->count();

        if ($holders > 0) {
            return $this->error(
                'This role is still assigned to active users.',
                409,
                ['role' => ["{$holders} active users hold this role"]]
            );
        }

        return null;
    }

    private function isProtected(Role $role): bool
    {
        return $role->key === Role::DEPARTMENT_HEAD;
    }
}
