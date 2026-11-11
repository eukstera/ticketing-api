<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the id cursor (after_id) of the new-ticket alarm poll.
 */
class NewTicketsRequest extends FormRequest
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
            'after_id' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
