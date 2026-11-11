<?php

namespace App\Http\Requests\Admin;

/**
 * Validation for the nature of work admin form (a unique label). The rules are in LabelRequest.
 */
class NatureOfWorkRequest extends LabelRequest
{
    protected function table(): string
    {
        return 'nature_of_work';
    }
}
