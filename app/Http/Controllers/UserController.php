<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListDesignationsRequest;
use App\Http\Requests\ListEmployeesRequest;
use App\Http\Requests\StoreDesignationRequest;
use App\Models\Designation;
use App\Models\User;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;

/**
 * Employee and designation lists used when assigning tickets and creating accounts. Creating a
 * designation is admin only.
 */
class UserController extends Controller
{
    use ApiResponseTrait;

    /**
     * GET /api/employees. Active users of a department (default: the caller's) with their designation, for
     * the assign list.
     */
    public function index(ListEmployeesRequest $request): JsonResponse
    {
        $departmentId = $request->validated('department_id') ?? $request->user()->department_id;

        $users = User::where('users.department_id', $departmentId)
            ->where('users.status', true)
            ->leftJoin('designations', 'designations.id', 'users.designation_id')
            ->select('users.id', 'users.department_id', 'users.first_name', 'users.last_name', 'designations.designation')
            ->orderBy('users.first_name')
            ->orderBy('users.last_name')
            ->get();

        return $this->success('Users retrieved successfully', [
            'result' => ['users' => $users],
        ]);
    }

    /**
     * GET /api/designations (public). The active designations, used by the account screens.
     */
    public function getDesignations(ListDesignationsRequest $request): JsonResponse
    {
        $designations = Designation::where('department_id', $request->validated('department_id'))
            ->where('status', true)
            ->get();

        return $this->success('Designations retrieved successfully', [
            'result' => ['designations' => $designations],
        ]);
    }

    /**
     * POST /api/designations (admin only). Adds a designation to a department.
     */
    public function storeDesignation(StoreDesignationRequest $request): JsonResponse
    {
        $designation = Designation::create($request->safe()->only(['department_id', 'designation']));

        return $this->success('Designation created successfully', [
            'result' => ['designation' => $designation],
        ], 201);
    }
}
