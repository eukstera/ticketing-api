<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a new comment: 1 to 2000 characters.
 */
class StoreCommentRequest extends FormRequest
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
            'comment' => ['required', 'string', 'max:2000'],
        ];
    }
}
