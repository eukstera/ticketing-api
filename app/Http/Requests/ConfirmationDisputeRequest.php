<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the requester's "not fixed" report from the emailed link: a reason of 10 to 500
 * characters.
 */
class ConfirmationDisputeRequest extends FormRequest
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
        return ['reason' => ['required', 'string', 'min:10', 'max:500']];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }
}
