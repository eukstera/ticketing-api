<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\ConcernTypeRequest;
use App\Http\Resources\Admin\LabelResource;
use App\Models\TypeOfConcern;

/**
 * Admin CRUD for the IT "type of concern" list. The shared behaviour (list, create, update, soft
 * deactivate) is in AdminResourceController.
 */
class ConcernTypeController extends AdminResourceController
{
    protected function modelClass(): string
    {
        return TypeOfConcern::class;
    }

    protected function requestClass(): string
    {
        return ConcernTypeRequest::class;
    }

    protected function resourceClass(): string
    {
        return LabelResource::class;
    }

    protected function listKey(): string
    {
        return 'concern_types';
    }

    protected function itemKey(): string
    {
        return 'concern_type';
    }

    protected function noun(): string
    {
        return 'Concern type';
    }

    protected function searchColumn(): string
    {
        return 'label';
    }
}
