<?php

namespace App\Http\Requests\Admin;

use App\Models\Designation;
use Illuminate\Validation\Validator;

class DesignationRequest extends AdminResourceRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'designation' => [$this->presence(), 'string', 'max:255'],
            'department_id' => [$this->presence(), 'integer', 'exists:departments,id'],
            'status' => $this->statusRules(),
        ];
    }

    /**
     * A designation name is unique (case-insensitively) within its department,
     * judged on the effective values: a PATCH that only moves the row to
     * another department is checked against that department's names.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['designation', 'department_id'])) {
                return;
            }

            $current = $this->ignoredId() === null ? null : Designation::query()->find($this->ignoredId());
            $name = $this->input('designation', $current?->designation);
            $departmentId = $this->input('department_id', $current?->department_id);

            if ($name === null || $departmentId === null) {
                return;
            }

            $taken = Designation::query()
                ->whereRaw('LOWER(designation) = ?', [mb_strtolower(trim($name))])
                ->where('department_id', $departmentId)
                ->when($current, fn ($query) => $query->whereKeyNot($current->id))
                ->exists();

            if ($taken) {
                $validator->errors()->add(
                    $this->has('designation') ? 'designation' : 'department_id',
                    'This designation already exists in the selected department.'
                );
            }
        }];
    }
}
