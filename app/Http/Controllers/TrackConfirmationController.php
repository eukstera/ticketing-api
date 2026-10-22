<?php

namespace App\Http\Controllers;

use App\Http\Requests\TrackConfirmationRequest;
use App\Http\Resources\ConfirmationPublicResource;
use App\Models\TicketConfirmation;
use App\Models\TicketSurvey;
use App\Services\CompletionConfirmationService;
use App\Services\ConfirmationException;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;

/**
 * The same confirmation actions as the emailed link, for requesters who open
 * Track Ticket instead: the ticket number plus the name given on the request
 * identify the ticket. Every answer is recorded with channel "track".
 */
class TrackConfirmationController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private CompletionConfirmationService $confirmations) {}

    public function confirm(TrackConfirmationRequest $request): JsonResponse
    {
        return $this->act($request, fn (TicketConfirmation $confirmation) => $this->confirmations->confirm(
            $confirmation,
            TicketConfirmation::CHANNEL_TRACK,
            $request,
        ), 'Thank you. The ticket is confirmed.');
    }

    public function dispute(TrackConfirmationRequest $request): JsonResponse
    {
        return $this->act($request, function (TicketConfirmation $confirmation) use ($request) {
            [$disputed, $logs] = $this->confirmations->dispute(
                $confirmation,
                TicketConfirmation::CHANNEL_TRACK,
                trim($request->validated('reason')),
                $request,
            );

            foreach ($logs as $log) {
                $this->confirmations->dispatchMail($log);
            }

            return $disputed;
        }, 'Thank you. The technician has been notified.');
    }

    public function survey(TrackConfirmationRequest $request): JsonResponse
    {
        try {
            $confirmation = $this->latest($request);

            $this->confirmations->submitSurvey(
                $confirmation,
                collect($request->validated('ratings'))->only(TicketSurvey::BLOCKS)->map(fn ($value) => (int) $value)->all(),
                $request->validated('comments'),
                $request,
            );
        } catch (ConfirmationException $e) {
            return $this->error($e->getMessage(), $e->status);
        }

        return $this->success('Thank you for your feedback', [], 201);
    }

    /**
     * @param  callable(TicketConfirmation): TicketConfirmation  $action
     */
    private function act(TrackConfirmationRequest $request, callable $action, string $message): JsonResponse
    {
        try {
            $confirmation = $action($this->latest($request));
        } catch (ConfirmationException $e) {
            return $this->error($e->getMessage(), $e->status);
        }

        return $this->success($message, [
            'result' => ['confirmation' => (new ConfirmationPublicResource($confirmation))->resolve()],
        ]);
    }

    private function latest(TrackConfirmationRequest $request): TicketConfirmation
    {
        [, $confirmation] = $this->confirmations->findForRequester(
            $request->validated('ticket_number'),
            $request->validated('requestor'),
        );

        return $confirmation ?? throw new ConfirmationException('This ticket is not waiting for confirmation.');
    }
}
