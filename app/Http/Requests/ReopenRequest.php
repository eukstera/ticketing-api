<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates reopening a false-alarm ticket (Section Heads only): an optional reason of 3 to 500
 * characters.
 */
class ReopenRequest extends FormRequest
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
            'reason' => ['nullable', 'string', 'min:3', 'max:500'],
        ];
    }
}
