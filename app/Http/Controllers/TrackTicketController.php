<?php

namespace App\Http\Controllers;

use App\Http\Requests\TrackTicketRequest;
use App\Models\Ticket;
use App\Models\TicketDateActivity;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * Public ticket lookup for requesters: ticket number plus the requester's
 * name as submitted. Exposes labels and dates only, never ids or people.
 */
class TrackTicketController extends Controller
{
    use ApiResponseTrait;

    public function show(TrackTicketRequest $request): JsonResponse
    {
        $ticket = Ticket::with([
            'ticketStatus',
            'priority',
            'handlingDepartment',
            'itDetails',
            'engineeringDetails',
            'dateActivities.dateCategory',
        ])->where('ticket_number', trim($request->validated('ticket_number')))->first();

        $requested = $ticket?->dateActivities
            ->first(fn (TicketDateActivity $activity) => $activity->dateCategory?->category === 'Requested Date');

        if ($ticket === null
            || $requested === null
            || $this->normalize((string) $requested->requestor) !== $this->normalize($request->validated('requestor'))) {
            return $this->error('Ticket not found.', 404);
        }

        $details = $ticket->itDetails ?? $ticket->engineeringDetails;

        return $this->success('Ticket found', [
            'result' => [
                'ticket' => [
                    'ticket_number' => $ticket->ticket_number,
                    'status' => $ticket->ticketStatus->label,
                    'priority' => $ticket->priority->level,
                    'department' => $ticket->handlingDepartment?->department,
                    'name_of_area' => $details?->name_of_area,
                    'details' => $ticket->itDetails ? $ticket->itDetails->remarks : $ticket->engineeringDetails?->scope_of_work,
                    'requested_at' => $requested->created_at?->toIso8601String(),
                    'assigned_at' => $this->dateOf($ticket->dateActivities, 'Assigned Date'),
                    'completed_at' => $this->dateOf($ticket->dateActivities, 'Completed Date'),
                ],
            ],
        ]);
    }

    /**
     * Trimmed, single-spaced and lowercased, so "  juan   DELA cruz " matches "Juan Dela Cruz".
     */
    private function normalize(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    /**
     * @param  Collection<int, TicketDateActivity>  $activities
     */
    private function dateOf(Collection $activities, string $category): ?string
    {
        return $activities
            ->first(fn (TicketDateActivity $activity) => $activity->dateCategory?->category === $category)
            ?->created_at?->toIso8601String();
    }
}
