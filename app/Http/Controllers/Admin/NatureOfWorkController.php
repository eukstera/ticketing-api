<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\NatureOfWorkRequest;
use App\Http\Resources\Admin\LabelResource;
use App\Models\NatureOfWork;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;

class NatureOfWorkController extends AdminResourceController
{
    public const PROTECTED_LABEL = 'Others';

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

    /**
     * @param  NatureOfWork  $row
     */
    protected function guardUpdate(Model $row, array $attributes): ?JsonResponse
    {
        if ($this->isProtected($row) && ($attributes['label'] ?? $row->label) !== $row->label) {
            return $this->error('The "Others" nature of work cannot be renamed.', 409);
        }

        return null;
    }

    /**
     * @param  NatureOfWork  $row
     */
    protected function guardDeactivate(Model $row): ?JsonResponse
    {
        if ($this->isProtected($row)) {
            return $this->error('The "Others" nature of work cannot be deactivated.', 409);
        }

        return null;
    }

    /** Ticket creation and the ticket lists treat the "Others" label specially. */
    private function isProtected(NatureOfWork $natureOfWork): bool
    {
        return $natureOfWork->label === self::PROTECTED_LABEL;
    }
}
