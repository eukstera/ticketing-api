<?php

namespace App\Http\Resources;

use App\Models\Ticket;
use App\Models\TicketDateActivity;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * @mixin Ticket
 */
class TicketDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $requested = $this->dateActivities->first(fn (TicketDateActivity $activity) => $activity->dateCategory?->category === 'Requested Date');
        $clockStart = $this->clockStartedAt();
        $dueAt = $this->dueAt();

        return [
            'id' => $this->id,
            'ticket_number' => $this->ticket_number,
            'department' => [
                'id' => (int) $this->department,
                'name' => $this->handlingDepartment?->department,
            ],
            'status' => ['id' => $this->status_id, 'label' => $this->ticketStatus->label],
            'priority' => [
                'id' => $this->priority_id,
                'level' => $this->priority->level,
                'sla_days' => (int) $this->priority->sla_days,
            ],
            'type' => $this->itDetails ? 'IT' : 'Engineering',
            'details' => $this->details(),
            'requester' => $this->requester($requested),
            'assignee' => $this->assignedUser ? $this->person($this->assignedUser) : null,
            'is_overdue' => $this->isOverdue($dueAt),
            'due_at' => $dueAt?->toIso8601String(),
            'timeline' => $this->dateActivities
                ->sortBy(fn (TicketDateActivity $activity) => [$activity->dateCategory?->category === 'Requested Date' ? 0 : 1, $activity->id])
                ->values()
                ->map(fn (TicketDateActivity $activity) => [
                    'id' => $activity->id,
                    'category' => $activity->dateCategory?->category,
                    'user' => $activity->display_name,
                    'remarks' => $activity->remarks,
                    'created_at' => $activity->created_at?->toIso8601String(),
                ])
                ->all(),
            'requester_email' => $this->requester_email,
            'clock_started_at' => $clockStart?->toIso8601String(),
            'false_alarm' => $this->falseAlarm(),
            'reopened' => $this->reopened(),
            'blank_job_order' => $this->blankJobOrder(),
            'void' => $this->voided(),
            'confirmation' => $this->confirmation ? (new ConfirmationStaffResource($this->confirmation))->resolve() : null,
            'comments' => $this->comments
                ->sortBy([['created_at', 'asc'], ['id', 'asc']])
                ->values()
                ->map(fn ($comment) => [
                    'id' => $comment->id,
                    'comment' => $comment->comment,
                    'user' => $comment->user ? $this->person($comment->user) : null,
                    'created_at' => $comment->created_at?->toIso8601String(),
                    'updated_at' => $comment->updated_at?->toIso8601String(),
                ])
                ->all(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Who rejected the ticket as a false alarm, why and when; null unless it
     * is a false alarm right now (the latest rejection if it was rejected twice).
     *
     * @return array{reason: ?string, marked_by: ?array<string, mixed>, marked_at: ?string}|null
     */
    private function falseAlarm(): ?array
    {
        $activity = $this->ticketStatus->label === 'False Alarm' ? $this->latestActivity('False Alarm Date') : null;

        if ($activity === null) {
            return null;
        }

        return [
            'reason' => $activity->remarks,
            'marked_by' => $activity->user ? $this->person($activity->user) : null,
            'marked_at' => $activity->created_at?->toIso8601String(),
        ];
    }

    /**
     * When and by whom a blank job order was printed, and when it was filled in;
     * null for tickets that never started as a blank form.
     *
     * @return array{printed_by: ?array<string, mixed>, printed_at: ?string, filled_in_at: ?string}|null
     */
    private function blankJobOrder(): ?array
    {
        $printed = $this->latestActivity('Blank JO Printed Date');

        if ($printed === null) {
            return null;
        }

        return [
            'printed_by' => $printed->user ? $this->person($printed->user) : null,
            'printed_at' => $printed->created_at?->toIso8601String(),
            'filled_in_at' => $this->latestActivity('Filled In Date')?->created_at?->toIso8601String(),
        ];
    }

    /**
     * Who voided the blank job order, why and when; null unless it is void.
     *
     * @return array{reason: ?string, voided_by: ?array<string, mixed>, voided_at: ?string}|null
     */
    private function voided(): ?array
    {
        $activity = $this->ticketStatus->label === 'Void' ? $this->latestActivity('Void Date') : null;

        if ($activity === null) {
            return null;
        }

        return [
            'reason' => $activity->remarks,
            'voided_by' => $activity->user ? $this->person($activity->user) : null,
            'voided_at' => $activity->created_at?->toIso8601String(),
        ];
    }

    /**
     * The latest reopen: who, why (optional) and when; null if never reopened.
     *
     * @return array{reason: ?string, reopened_by: ?array<string, mixed>, reopened_at: ?string}|null
     */
    private function reopened(): ?array
    {
        $activity = $this->latestActivity('Reopened Date');

        if ($activity === null) {
            return null;
        }

        return [
            'reason' => $activity->remarks,
            'reopened_by' => $activity->user ? $this->person($activity->user) : null,
            'reopened_at' => $activity->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function details(): array
    {
        if ($it = $this->itDetails) {
            return [
                'type_of_concern' => $it->concernType?->label,
                'type_of_tech_support' => $it->type_of_tech_support,
                'name_of_area' => $it->name_of_area,
                'area_supervisor' => $it->area_supervisor,
                'remarks' => $it->remarks,
                'devices' => $this->devices
                    ->map(fn ($device) => ['name' => $device->device, 'qty' => (int) $device->qty])
                    ->values()
                    ->all(),
            ];
        }

        $engineering = $this->engineeringDetails;

        return [
            'nature_of_work' => $engineering?->natureOfWork?->label,
            'other_nature_of_work' => $engineering?->other_nature_of_work,
            'scope_of_work' => $engineering?->scope_of_work,
            'name_of_area' => $engineering?->name_of_area,
            'area_supervisor' => $engineering?->area_supervisor,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requester(?TicketDateActivity $requested): array
    {
        if ($requested?->user) {
            return $this->person($requested->user);
        }

        return ['name' => $requested?->requestor];
    }

    /**
     * @return array{id: int, first_name: string, last_name: string}
     */
    private function person(User $user): array
    {
        return ['id' => $user->id, 'first_name' => $user->first_name, 'last_name' => $user->last_name];
    }

    private function isOverdue(?Carbon $dueAt): bool
    {
        return Ticket::isPastDue($this->ticketStatus->label, $dueAt);
    }
}
