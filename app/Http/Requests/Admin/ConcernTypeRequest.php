<?php

namespace App\Http\Requests\Admin;

class ConcernTypeRequest extends LabelRequest
{
    protected function table(): string
    {
        return 'type_of_concern';
    }
}
