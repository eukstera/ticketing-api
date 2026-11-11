<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the employee list query: an optional department id (default: the caller's department).
 */
class ListEmployeesRequest extends FormRequest
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
            'department_id' => ['sometimes', 'integer', 'exists:departments,id'],
        ];
    }
}
