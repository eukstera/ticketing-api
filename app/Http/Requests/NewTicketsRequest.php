<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
