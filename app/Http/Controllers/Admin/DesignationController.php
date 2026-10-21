<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\DesignationRequest;
use App\Http\Requests\Admin\ListAdminResourceRequest;
use App\Http\Resources\Admin\DesignationResource;
use App\Models\Designation;
use Illuminate\Database\Eloquent\Builder;

/**
 * Creation stays on POST /designations; only list, update and deactivate live here.
 */
class DesignationController extends AdminResourceController
{
    protected function modelClass(): string
    {
        return Designation::class;
    }

    protected function requestClass(): string
    {
        return DesignationRequest::class;
    }

    protected function resourceClass(): string
    {
        return DesignationResource::class;
    }

    protected function listKey(): string
    {
        return 'designations';
    }

    protected function itemKey(): string
    {
        return 'designation';
    }

    protected function noun(): string
    {
        return 'Designation';
    }

    protected function searchColumn(): string
    {
        return 'designation';
    }

    protected function applyFilters(Builder $query, ListAdminResourceRequest $request): void
    {
        if ($request->filled('department_id')) {
            $query->where('department_id', $request->integer('department_id'));
        }
    }
}
