<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the paging, search, status filter (active, inactive, all) and optional department of the admin list endpoints.
 */
class ListAdminResourceRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive,all'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ];
    }
}
