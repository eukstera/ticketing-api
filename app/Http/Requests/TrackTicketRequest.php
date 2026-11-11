<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates Track Ticket: a ticket number and, optionally, the requester name.
 */
class TrackTicketRequest extends FormRequest
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
            'ticket_number' => ['required', 'string', 'max:50'],
            'requestor' => ['nullable', 'string', 'max:150'],
        ];
    }
}
