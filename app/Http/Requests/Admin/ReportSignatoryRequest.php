<?php

namespace App\Http\Requests\Admin;

use App\Models\ReportSignatory;
use Illuminate\Validation\Validator;

class ReportSignatoryRequest extends AdminResourceRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => [$this->presence(), 'string', 'max:255'],
            'title' => [$this->presence(), 'string', 'max:255'],
            'is_default' => ['sometimes', 'boolean'],
            'status' => $this->statusRules(),
        ];
    }

    /**
     * Name and title are unique together (case-insensitively), judged on the
     * effective values so a partial update is checked against the stored half.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['name', 'title'])) {
                return;
            }

            $current = $this->ignoredId() === null ? null : ReportSignatory::query()->find($this->ignoredId());
            $name = $this->input('name', $current?->name);
            $title = $this->input('title', $current?->title);

            if ($name === null || $title === null) {
                return;
            }

            $taken = ReportSignatory::query()
                ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])
                ->whereRaw('LOWER(title) = ?', [mb_strtolower(trim($title))])
                ->when($current, fn ($query) => $query->whereKeyNot($current->id))
                ->exists();

            if ($taken) {
                $validator->errors()->add(
                    $this->has('name') ? 'name' : 'title',
                    'This signatory already exists.'
                );
            }
        }];
    }
}
