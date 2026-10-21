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
        $dueAt = $this->dueAt($requested);

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
                ->sortBy([['date_category_id', 'asc'], ['id', 'asc']])
                ->values()
                ->map(fn (TicketDateActivity $activity) => [
                    'id' => $activity->id,
                    'category' => $activity->dateCategory?->category,
                    'user' => $activity->display_name,
                    'created_at' => $activity->created_at?->toIso8601String(),
                ])
                ->all(),
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

    /**
     * Whole calendar days, matching tickets:promote-overdue: a ticket is due
     * at the start of the day sla_days after it was requested.
     */
    private function dueAt(?TicketDateActivity $requested): ?Carbon
    {
        $days = (int) $this->priority->sla_days;

        if ($requested === null || $days <= 0) {
            return null;
        }

        return $requested->created_at->copy()->addDays($days)->startOfDay();
    }

    private function isOverdue(?Carbon $dueAt): bool
    {
        return match ($this->ticketStatus->label) {
            'Overdue' => true,
            'Completed' => false,
            default => $dueAt !== null && now()->greaterThanOrEqualTo($dueAt),
        };
    }
}
