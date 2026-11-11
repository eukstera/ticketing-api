<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the optional format (xlsx or pdf) of the job order download.
 */
class JobOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'format' => ['nullable', 'string', Rule::in(['xlsx', 'pdf'])],
        ];
    }
}
