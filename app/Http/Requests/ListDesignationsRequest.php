<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the designation list query: a department id is required.
 */
class ListDesignationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'department_id' => ['required', 'integer', 'exists:departments,id'],
        ];
    }
}
