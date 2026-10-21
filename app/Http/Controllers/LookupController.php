<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\NatureOfWork;
use App\Models\Role;
use App\Models\TypeOfConcern;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;

class LookupController extends Controller
{
    use ApiResponseTrait;

    public function concernTypes(): JsonResponse
    {
        $concernTypes = TypeOfConcern::where('status', true)->orderBy('id')->get(['id', 'label']);

        return $this->success('Concern types retrieved successfully', [
            'result' => ['concern_types' => $concernTypes],
        ]);
    }

    public function natureOfWork(): JsonResponse
    {
        $natureOfWork = NatureOfWork::where('status', true)->orderBy('id')->get(['id', 'label']);

        return $this->success('Nature of work retrieved successfully', [
            'result' => ['nature_of_work' => $natureOfWork],
        ]);
    }

    public function roles(): JsonResponse
    {
        $roles = Role::where('status', true)
            ->orderBy('id')
            ->get(['id', 'role', 'status'])
            ->map(fn (Role $role) => ['id' => $role->id, 'name' => $role->role, 'status' => (bool) $role->status]);

        return $this->success('Roles retrieved successfully', [
            'result' => ['roles' => $roles],
        ]);
    }

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
