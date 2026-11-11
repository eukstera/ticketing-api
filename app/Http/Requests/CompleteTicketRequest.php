<?php

namespace App\Http\Requests;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Validates completing a ticket: notes of 10 to 2000 characters. The notes become the "action taken"
 * in reports.
 */
class CompleteTicketRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('complete', $this->route('ticket'));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'notes' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('notes'))) {
            $this->merge(['notes' => trim($this->input('notes'))]);
        }
    }
}
