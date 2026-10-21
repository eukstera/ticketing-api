<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\ListAdminResourceRequest;
use App\Http\Requests\Admin\PriorityRequest;
use App\Http\Resources\Admin\PriorityResource;
use App\Models\Priority;
use Illuminate\Database\Eloquent\Builder;

class PriorityController extends AdminResourceController
{
    protected function modelClass(): string
    {
        return Priority::class;
    }

    protected function requestClass(): string
    {
        return PriorityRequest::class;
    }

    protected function resourceClass(): string
    {
        return PriorityResource::class;
    }

    protected function listKey(): string
    {
        return 'priorities';
    }

    protected function itemKey(): string
    {
        return 'priority';
    }

    protected function noun(): string
    {
        return 'Priority';
    }

    protected function searchColumn(): string
    {
        return 'level';
    }

    protected function applyFilters(Builder $query, ListAdminResourceRequest $request): void
    {
        if ($request->filled('department_id')) {
            $query->where('department_id', $request->integer('department_id'));
        }
    }

    /**
     * Keeps the legacy string SLA column in step with sla_days.
     */
    protected function attributesFrom(array $payload): array
    {
        $attributes = parent::attributesFrom($payload);

        if (array_key_exists('sla_days', $attributes)) {
            $attributes['sla'] = (string) $attributes['sla_days'];
        }

        return $attributes;
    }
}
