<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\NatureOfWork;
use App\Models\Role;
use App\Models\TypeOfConcern;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;

/**
 * Read-only lookup lists (concern types, nature of work, departments, roles) for the ticket forms and
 * the account screens. Returns active rows only.
 */
class LookupController extends Controller
{
    use ApiResponseTrait;

    /**
     * GET /api/lookups/concern-types. Active IT concern types.
     */
    public function concernTypes(): JsonResponse
    {
        $concernTypes = TypeOfConcern::where('status', true)->orderBy('id')->get(['id', 'label']);

        return $this->success('Concern types retrieved successfully', [
            'result' => ['concern_types' => $concernTypes],
        ]);
    }

    /**
     * GET /api/lookups/nature-of-work. Active Engineering natures of work.
     */
    public function natureOfWork(): JsonResponse
    {
        $natureOfWork = NatureOfWork::where('status', true)->orderBy('id')->get(['id', 'label']);

        return $this->success('Nature of work retrieved successfully', [
            'result' => ['nature_of_work' => $natureOfWork],
        ]);
    }

    /**
     * GET /api/roles. Active roles as {id, name, key, status}.
     */
    public function roles(): JsonResponse
    {
        $roles = Role::where('status', true)
            ->orderBy('id')
            ->get(['id', 'role', 'key', 'status'])
            ->map(fn (Role $role) => ['id' => $role->id, 'name' => $role->role, 'key' => $role->key, 'status' => (bool) $role->status]);

        return $this->success('Roles retrieved successfully', [
            'result' => ['roles' => $roles],
        ]);
    }

    /**
     * GET /api/lookups/departments. Active departments.
     */
    public function departments(): JsonResponse
    {
        $departments = Department::where('status', true)
            ->orderBy('id')
            ->get(['id', 'department'])
            ->map(fn (Department $department) => ['id' => $department->id, 'name' => $department->department]);

        return $this->success('Departments retrieved successfully', [
            'result' => ['departments' => $departments],
        ]);
    }
}
