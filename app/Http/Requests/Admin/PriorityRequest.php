<?php

namespace App\Http\Requests\Admin;

use App\Models\Priority;
use Illuminate\Validation\Validator;

class PriorityRequest extends AdminResourceRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'level' => [$this->presence(), 'string', 'max:255'],
            'sla_days' => [$this->presence(), 'integer', 'min:0', 'max:3650'],
            'department_id' => [$this->presence(), 'integer', 'exists:departments,id'],
            'status' => $this->statusRules(),
        ];
    }

    /**
     * A priority level is unique (case-insensitively) within its department,
     * judged on the effective values, so moving a priority to another
     * department is checked against that department's levels.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['level', 'department_id'])) {
                return;
            }

            $current = $this->ignoredId() === null ? null : Priority::query()->find($this->ignoredId());
            $level = $this->input('level', $current?->level);
            $departmentId = $this->input('department_id', $current?->department_id);

            if ($level === null || $departmentId === null) {
                return;
            }

            $taken = Priority::query()
                ->whereRaw('LOWER(level) = ?', [mb_strtolower(trim($level))])
                ->where('department_id', $departmentId)
                ->when($current, fn ($query) => $query->whereKeyNot($current->id))
                ->exists();

            if ($taken) {
                $validator->errors()->add(
                    $this->has('level') ? 'level' : 'department_id',
                    'This priority level already exists in the selected department.'
                );
            }
        }];
    }
}
