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

        // Without a name only the minimal view is returned; a name that is
        // sent must match, and unlocks the details.
        $requestor = $request->validated('requestor');
        $withName = $requestor !== null && trim($requestor) !== '';

        if ($ticket === null
            || ($withName && $this->normalize((string) $requested?->requestor) !== $this->normalize($requestor))) {
            return $this->error('Ticket not found.', 404);
        }

        $payload = [
            'ticket_number' => $ticket->ticket_number,
            'status' => $ticket->ticketStatus->label,
            'priority' => $ticket->priority->level,
            'department' => $ticket->handlingDepartment?->department,
            'name_of_area' => ($ticket->itDetails ?? $ticket->engineeringDetails)?->name_of_area,
            'requested_at' => $requested?->created_at?->toIso8601String(),
            'assigned_at' => $this->dateOf($ticket->dateActivities, 'Assigned Date'),
            'completed_at' => $this->dateOf($ticket->dateActivities, 'Completed Date'),
        ];

        if ($withName) {
            $payload['details'] = $ticket->itDetails ? $ticket->itDetails->remarks : $ticket->engineeringDetails?->scope_of_work;

            if ($ticket->ticketStatus->label === 'False Alarm') {
                $payload['false_alarm_reason'] = $ticket->dateActivities
                    ->first(fn (TicketDateActivity $activity) => $activity->dateCategory?->category === 'False Alarm Date')
                    ?->remarks;
            }
        }

        return $this->success('Ticket found', ['result' => ['ticket' => $payload]]);
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
