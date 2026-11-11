<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the optional month (YYYY-MM) of the dashboard numbers.
 */
class DashboardRequest extends FormRequest
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
            'date' => ['nullable', 'date_format:Y-m'],
            'priority' => ['nullable', 'string'],
        ];
    }
}
