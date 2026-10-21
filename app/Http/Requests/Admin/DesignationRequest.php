<?php

namespace App\Http\Requests\Admin;

use App\Models\Designation;
use Illuminate\Validation\Rule;

class DesignationRequest extends AdminResourceRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'designation' => [
                $this->presence(), 'string', 'max:255',
                Rule::unique('designations', 'designation')
                    ->where('department_id', $this->departmentId())
                    ->ignore($this->ignoredId()),
            ],
            'department_id' => [$this->presence(), 'integer', 'exists:departments,id'],
            'status' => $this->statusRules(),
        ];
    }

    /** The department the name must be unique within: the new one, else the current one. */
    private function departmentId(): ?int
    {
        return $this->input('department_id')
            ?? Designation::query()->whereKey($this->ignoredId())->value('department_id');
    }
}
