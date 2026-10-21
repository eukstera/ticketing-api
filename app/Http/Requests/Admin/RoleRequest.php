<?php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;

class RoleRequest extends AdminResourceRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => [
                $this->presence(), 'string', 'max:255',
                Rule::unique('roles', 'role')->ignore($this->ignoredId()),
            ],
            'status' => $this->statusRules(),
        ];
    }
}
