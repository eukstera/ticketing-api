<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\DepartmentRequest;
use App\Http\Resources\Admin\DepartmentResource;
use App\Models\Department;

class DepartmentController extends AdminResourceController
{
    protected function modelClass(): string
    {
        return Department::class;
    }

    protected function requestClass(): string
    {
        return DepartmentRequest::class;
    }

    protected function resourceClass(): string
    {
        return DepartmentResource::class;
    }

    protected function listKey(): string
    {
        return 'departments';
    }

    protected function itemKey(): string
    {
        return 'department';
    }

    protected function noun(): string
    {
        return 'Department';
    }

    protected function searchColumn(): string
    {
        return 'department';
    }

    protected function columnMap(): array
    {
        return ['name' => 'department'];
    }
}
