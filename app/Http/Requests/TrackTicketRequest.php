<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'requestor' => ['required', 'string', 'max:150'],
        ];
    }
}
