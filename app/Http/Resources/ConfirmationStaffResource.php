<?php

namespace App\Http\Resources;

use App\Models\TicketConfirmation;
use App\Models\TicketMailLog;
use App\Models\TicketSurvey;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * The confirmation record as staff see it on the ticket detail.
 *
 * @mixin TicketConfirmation
 */
class ConfirmationStaffResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['completedBy', 'survey', 'mailLogs', 'ticket']);

        // The satisfaction answers are for the handling department and Department Heads only.
        $viewer = $request->user();
        $canSeeAnswers = $viewer !== null
            && ((int) $viewer->department_id === (int) $this->ticket?->department || Gate::forUser($viewer)->allows('admin'));

        $mail = $this->mailLogs
            ->where('type', TicketMailLog::TYPE_CONFIRMATION)
            ->sortByDesc('id')
            ->first();

        return [
            'state' => $this->state,
            'completion_notes' => $this->completion_notes,
            'completed_by' => $this->completedBy
                ? ['id' => $this->completedBy->id, 'first_name' => $this->completedBy->first_name, 'last_name' => $this->completedBy->last_name]
                : null,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'auto_close_at' => $this->expires_at?->toIso8601String(),
            'channel' => $this->channel,
            'responded_at' => $this->responded_at?->toIso8601String(),
            'dispute_reason' => $this->dispute_reason,
            'requester_email' => $this->requester_email,
            'mail' => [
                'status' => match ($mail?->status) {
                    null => 'none',
                    TicketMailLog::GAVE_UP => TicketMailLog::FAILED,
                    default => $mail->status,
                },
                'attempts' => (int) ($mail?->attempts ?? 0),
                'sent_at' => $mail?->sent_at?->toIso8601String(),
                'error' => $mail?->last_error,
            ],
            'survey' => [
                'submitted' => $this->survey !== null,
                'submitted_at' => $this->survey?->submitted_at?->toIso8601String(),
                'ratings' => $canSeeAnswers && $this->survey !== null
                    ? collect(TicketSurvey::BLOCKS)->mapWithKeys(fn (string $block) => [$block => (int) $this->survey->{$block}])->all()
                    : null,
                'comments' => $canSeeAnswers ? $this->survey?->comments : null,
            ],
        ];
    }
}
