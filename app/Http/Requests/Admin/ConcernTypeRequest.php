<?php

namespace App\Http\Requests\Admin;

/**
 * Validation for the concern type admin form (a unique label). The rules are in LabelRequest.
 */
class ConcernTypeRequest extends LabelRequest
{
    protected function table(): string
    {
        return 'type_of_concern';
    }
}
