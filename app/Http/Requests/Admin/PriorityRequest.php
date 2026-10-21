<?php

namespace App\Http\Requests\Admin;

use App\Models\Priority;
use Illuminate\Validation\Rule;

class PriorityRequest extends AdminResourceRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'level' => [
                $this->presence(), 'string', 'max:255',
                Rule::unique('priority', 'level')
                    ->where('department_id', $this->departmentId())
                    ->ignore($this->ignoredId()),
            ],
            'sla_days' => [$this->presence(), 'integer', 'min:0', 'max:3650'],
            'department_id' => [$this->presence(), 'integer', 'exists:departments,id'],
            'status' => $this->statusRules(),
        ];
    }

    /** The department the level must be unique within: the new one, else the current one. */
    private function departmentId(): ?int
    {
        return $this->input('department_id')
            ?? Priority::query()->whereKey($this->ignoredId())->value('department_id');
    }
}
