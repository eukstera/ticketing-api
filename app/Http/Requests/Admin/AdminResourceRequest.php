<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base for admin create (POST) / update (PATCH) requests: fields are
 * required on create and optional on update.
 */
abstract class AdminResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function presence(): string
    {
        return $this->isMethod('POST') ? 'required' : 'sometimes';
    }

    /** Id of the row being updated, so unique rules can ignore it. */
    protected function ignoredId(): ?int
    {
        $id = $this->route('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * @return array<int, string>
     */
    protected function statusRules(): array
    {
        return ['sometimes', 'boolean'];
    }
}
