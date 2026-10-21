<?php

namespace App\Http\Requests\Admin;

class NatureOfWorkRequest extends LabelRequest
{
    protected function table(): string
    {
        return 'nature_of_work';
    }
}
