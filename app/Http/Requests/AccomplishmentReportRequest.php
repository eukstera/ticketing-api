<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AccomplishmentReportRequest extends FormRequest
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
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'signatory_id' => [
                'nullable',
                'integer',
                Rule::exists('report_signatories', 'id')->where('status', true),
            ],
            'signatory_name' => ['nullable', 'required_with:signatory_title', 'string', 'max:255'],
            'signatory_title' => ['nullable', 'required_with:signatory_name', 'string', 'max:255'],
        ];
    }
}
