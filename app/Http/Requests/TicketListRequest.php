<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TicketListRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:100'],
            'status_id' => ['nullable', 'integer', 'exists:status,id'],
            'priority_id' => ['nullable', 'integer', 'exists:priority,id'],
            'status' => ['nullable', 'string'],
            'priority' => ['nullable', 'string'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'sort' => ['nullable', Rule::in(['requested_at', 'ticket_number', 'priority', 'status'])],
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],
        ];
    }
}
