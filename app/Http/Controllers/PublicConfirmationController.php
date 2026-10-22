<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConfirmationDisputeRequest;
use App\Http\Requests\ConfirmationSurveyRequest;
use App\Http\Resources\ConfirmationPublicResource;
use App\Models\TicketConfirmation;
use App\Models\TicketSurvey;
use App\Services\CompletionConfirmationService;
use App\Services\ConfirmationException;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The requester's side of the completion confirmation, reached from the
 * emailed link. The link token is the only credential; no login is needed.
 */
class PublicConfirmationController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private CompletionConfirmationService $confirmations) {}

    public function show(string $token): JsonResponse
    {
        try {
            $confirmation = $this->confirmations->findByToken($token);

            if ($confirmation->hasExpired()) {
                throw new ConfirmationException('This confirmation link has expired.', 410);
            }
        } catch (ConfirmationException $e) {
            return $this->error($e->getMessage(), $e->status);
        }

        return $this->summary('Confirmation retrieved', $confirmation);
    }

    public function confirm(Request $request, string $token): JsonResponse
    {
        return $this->act(fn () => $this->confirmations->confirm(
            $this->confirmations->findByToken($token),
            TicketConfirmation::CHANNEL_EMAIL,
            $request,
        ), 'Thank you. The ticket is confirmed.');
    }

    public function dispute(ConfirmationDisputeRequest $request, string $token): JsonResponse
    {
        return $this->act(function () use ($request, $token) {
            [$disputed, $logs] = $this->confirmations->dispute(
                $this->confirmations->findByToken($token),
                TicketConfirmation::CHANNEL_EMAIL,
                $request->validated('reason'),
                $request,
            );

            foreach ($logs as $log) {
                $this->confirmations->dispatchMail($log);
            }

            return $disputed;
        }, 'Thank you. The technician has been notified.');
    }

    public function survey(ConfirmationSurveyRequest $request, string $token): JsonResponse
    {
        try {
            $confirmation = $this->confirmations->findByToken($token);

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
     * @param  callable(): TicketConfirmation  $action
     */
    private function act(callable $action, string $message): JsonResponse
    {
        try {
            $confirmation = $action();
        } catch (ConfirmationException $e) {
            return $this->error($e->getMessage(), $e->status);
        }

        return $this->summary($message, $confirmation);
    }

    private function summary(string $message, TicketConfirmation $confirmation): JsonResponse
    {
        return $this->success($message, [
            'result' => ['confirmation' => (new ConfirmationPublicResource($confirmation))->resolve()],
        ]);
    }
}
