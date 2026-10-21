<?php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;

/**
 * Create / update for the label-only lookups (concern types, nature of work).
 */
abstract class LabelRequest extends AdminResourceRequest
{
    abstract protected function table(): string;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'label' => [
                $this->presence(), 'string', 'max:255',
                Rule::unique($this->table(), 'label')->ignore($this->ignoredId()),
            ],
            'status' => $this->statusRules(),
        ];
    }
}
