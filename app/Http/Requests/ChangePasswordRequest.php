<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

/**
 * Validates a password change: the current password must match, and the new one must be confirmed,
 * differ from the current one, and have at least 8 characters with upper and lower case letters and a
 * number.
 */
class ChangePasswordRequest extends FormRequest
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
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', Password::min(8)->mixedCase()->numbers()],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('current_password') || ! $this->filled('current_password')) {
                    return;
                }

                if (! Hash::check($this->input('current_password'), $this->user()->password)) {
                    $validator->errors()->add('current_password', 'The current password is incorrect.');
                }
            },
        ];
    }
}
