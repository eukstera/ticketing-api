<?php

namespace App\Http\Resources;

use App\Models\TicketConfirmation;
use App\Models\TicketDateActivity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a requester sees of their completed ticket: labels, dates and the
 * technician's notes, never ids or people other than the technician's name.
 *
 * @mixin TicketConfirmation
 */
class ConfirmationPublicResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing([
            'completedBy',
            'survey',
            'ticket.handlingDepartment',
            'ticket.itDetails.concernType',
            'ticket.engineeringDetails.natureOfWork',
            'ticket.dateActivities.dateCategory',
        ]);

        $ticket = $this->ticket;
        $details = $ticket->itDetails ?? $ticket->engineeringDetails;
        $requested = $ticket->dateActivities
            ->first(fn (TicketDateActivity $activity) => $activity->dateCategory?->category === 'Requested Date');
        $answerable = $this->isPending() && ! $this->hasExpired();

        return [
            'state' => $this->state,
            'can_confirm' => $answerable,
            'can_dispute' => $answerable,
            'survey' => [
                'required' => $this->state === TicketConfirmation::CONFIRMED && $this->survey === null,
                'submitted' => $this->survey !== null,
            ],
            'auto_close_at' => $this->expires_at?->toIso8601String(),
            'ticket' => [
                'ticket_number' => $ticket->ticket_number,
                'department' => $ticket->handlingDepartment?->department,
                'concern' => $ticket->itDetails?->concernType?->label
                    ?? $ticket->engineeringDetails?->natureOfWork?->label,
                'name_of_area' => $details?->name_of_area,
                'requested_at' => $requested?->created_at?->toIso8601String(),
            ],
            'completion' => [
                'notes' => $this->completion_notes,
                'completed_by' => $this->completedBy ? trim("{$this->completedBy->first_name} {$this->completedBy->last_name}") : null,
                'completed_at' => $this->completed_at?->toIso8601String(),
            ],
        ];
    }
}
