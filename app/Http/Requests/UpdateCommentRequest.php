<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates editing a comment: 1 to 2000 characters.
 */
class UpdateCommentRequest extends FormRequest
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
