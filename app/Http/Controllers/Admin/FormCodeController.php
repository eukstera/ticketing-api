<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\FormCodeRequest;
use App\Http\Resources\Admin\FormCodeResource;
use App\Models\FormCode;

/**
 * Admin list and edit of the form code and effectivity date printed in the Job Order header
 * (GET and PATCH /api/admin/form-codes). There is no create or delete: the routes only exist for
 * index and update, so POST and DELETE answer 405. The Job Order PDF and Excel read these rows on
 * every download, so an edit shows on the very next printout.
 */
class FormCodeController extends AdminResourceController
{
    protected function modelClass(): string
    {
        return FormCode::class;
    }

    protected function requestClass(): string
    {
        return FormCodeRequest::class;
    }

    protected function resourceClass(): string
    {
        return FormCodeResource::class;
    }

    protected function listKey(): string
    {
        return 'form_codes';
    }

    protected function itemKey(): string
    {
        return 'form_code';
    }

    protected function noun(): string
    {
        return 'Form code';
    }

    protected function searchColumn(): string
    {
        return 'name';
    }
}
