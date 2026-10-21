<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\NatureOfWorkRequest;
use App\Http\Resources\Admin\LabelResource;
use App\Models\NatureOfWork;

class NatureOfWorkController extends AdminResourceController
{
    protected function modelClass(): string
    {
        return NatureOfWork::class;
    }

    protected function requestClass(): string
    {
        return NatureOfWorkRequest::class;
    }

    protected function resourceClass(): string
    {
        return LabelResource::class;
    }

    protected function listKey(): string
    {
        return 'nature_of_work';
    }

    protected function itemKey(): string
    {
        return 'nature_of_work';
    }

    protected function noun(): string
    {
        return 'Nature of work';
    }

    protected function searchColumn(): string
    {
        return 'label';
    }
}
