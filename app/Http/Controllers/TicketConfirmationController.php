<?php

namespace App\Http\Controllers;

use App\Http\Requests\CloseOnBehalfRequest;
use App\Http\Resources\ConfirmationStaffResource;
use App\Models\Ticket;
use App\Services\CompletionConfirmationService;
use App\Services\ConfirmationException;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Staff actions on a ticket that is waiting for the requester's confirmation.
 */
class TicketConfirmationController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private CompletionConfirmationService $confirmations) {}

    /**
     * A Department Head closes the ticket on the requester's behalf.
     */
    public function close(CloseOnBehalfRequest $request, Ticket $ticket): JsonResponse
    {
        Gate::authorize('closeOnBehalf', $ticket);

        try {
            $confirmation = $this->confirmations->closeOnBehalf($ticket, $request->user(), $request->validated('reason'), $request);
        } catch (ConfirmationException $e) {
            return $this->error($e->getMessage(), $e->status);
        }

        return $this->success('Ticket closed on the requester\'s behalf', [
            'result' => [
                'ticket' => $ticket->fresh(),
                'confirmation' => (new ConfirmationStaffResource($confirmation))->resolve(),
            ],
        ]);
    }

    /**
     * Sends the requester a fresh link; the previous one stops working.
     */
    public function resend(Request $request, Ticket $ticket): JsonResponse
    {
        Gate::authorize('resendConfirmation', $ticket);

        try {
            [$confirmation, $log, $token] = $this->confirmations->resend($ticket);
        } catch (ConfirmationException $e) {
            return $this->error($e->getMessage(), $e->status);
        }

        $this->confirmations->dispatchMail($log, $token);

        return $this->success('Confirmation email queued', [
            'result' => ['confirmation' => (new ConfirmationStaffResource($confirmation->refresh()))->resolve()],
        ]);
    }
}
