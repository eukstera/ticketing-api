<?php

namespace App\Http\Requests;

use App\Models\TicketSurvey;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Identifies a ticket for Track Ticket actions by its number and the requester's name,
 * plus the reason (dispute) or survey answers (survey) depending on the route.
 */
class TrackConfirmationRequest extends FormRequest
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
        $rules = [
            'ticket_number' => ['required', 'string', 'max:50'],
            'requestor' => ['required', 'string', 'max:150'],
        ];

        if ($this->is('api/track/dispute')) {
            $rules['reason'] = ['required', 'string', 'min:10', 'max:500'];
        }

        if ($this->is('api/track/survey')) {
            $rules['ratings'] = ['required', 'array'];
            $rules['comments'] = ['nullable', 'string', 'max:1000'];

            foreach (TicketSurvey::BLOCKS as $block) {
                $rules["ratings.{$block}"] = ['required', 'integer', 'between:1,5'];
            }
        }

        return $rules;
    }
}
