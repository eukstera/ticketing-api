<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only the names are editable here; every other field in the payload is ignored.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required_without:last_name', 'string', 'max:100'],
            'last_name' => ['required_without:first_name', 'string', 'max:100'],
        ];
    }
}
