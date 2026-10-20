<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds tickets that need no pre-seeded data: every lookup row is
 * created on demand. Unless withoutDetails() is used, a ticket also gets
 * its IT/Engineering detail row and a Requested Date activity.
 *
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    protected $model = Ticket::class;

    protected bool $withoutDetails = false;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'department' => fn () => Department::query()->orderBy('id')->value('id') ?? DepartmentFactory::new()->create()->id,
            'status_id' => fn () => StatusFactory::labelled(StatusFactory::OPEN)->id,
            'priority_id' => fn (array $attributes) => PriorityFactory::forDepartmentId($attributes['department'])->id,
            'assigned_user' => null,
            'ticket_number' => sprintf(
                '%s-%03d-%s',
                now()->format('m'),
                fake()->unique()->numberBetween(1, 9999),
                now()->format('Y'),
            ),
        ];
    }

    public function open(): static
    {
        return $this->state(fn () => [
            'status_id' => StatusFactory::labelled(StatusFactory::OPEN)->id,
            'assigned_user' => null,
        ]);
    }

    /**
     * In-progress, handled by $user in $user's department.
     */
    public function assigned(User $user): static
    {
        return $this->state(fn () => [
            'status_id' => StatusFactory::labelled(StatusFactory::IN_PROGRESS)->id,
            'assigned_user' => $user->id,
            'department' => $user->department_id,
        ]);
    }

    /**
     * Completed by $by, or by a new user of the ticket's department when null.
     * Chain after assigned() to keep that assignee.
     */
    public function completed(?User $by = null): static
    {
        return $this->state(fn () => array_filter([
            'status_id' => StatusFactory::labelled(StatusFactory::COMPLETED)->id,
            'assigned_user' => $by?->id,
            'department' => $by?->department_id,
        ]));
    }

    public function overdue(): static
    {
        return $this->state(fn () => [
            'status_id' => StatusFactory::labelled(StatusFactory::OVERDUE)->id,
        ]);
    }

    public function forDepartment(Department|int $department): static
    {
        $departmentId = $department instanceof Department ? $department->id : $department;

        return $this->state(fn () => ['department' => $departmentId]);
    }

    /**
     * Sets the request time; the Requested Date activity mirrors it.
     */
    public function requestedAt(Carbon|string $moment): static
    {
        $moment = Carbon::parse($moment);

        return $this->state(fn () => ['created_at' => $moment, 'updated_at' => $moment]);
    }

    /**
     * Skips the detail row, devices and Requested Date activity.
     */
    public function withoutDetails(): static
    {
        $clone = $this->newInstance();
        $clone->withoutDetails = true;

        return $clone;
    }

    protected function newInstance(array $arguments = [])
    {
        $instance = parent::newInstance($arguments);
        $instance->withoutDetails = $this->withoutDetails;

        return $instance;
    }

    protected function callAfterCreating(Collection $instances, ?Model $parent = null)
    {
        $instances->each(fn (Ticket $ticket) => $this->createRelatedRows($ticket));

        parent::callAfterCreating($instances, $parent);
    }

    private function createRelatedRows(Ticket $ticket): void
    {
        if (! $this->withoutDetails) {
            $this->createDetails($ticket);

            TicketDateActivityFactory::new()->for($ticket, 'ticket')->requested()->create([
                'created_at' => $ticket->created_at,
                'updated_at' => $ticket->created_at,
            ]);
        }

        $completedId = StatusFactory::labelled(StatusFactory::COMPLETED)->id;

        if ($ticket->assigned_user === null && $ticket->status_id === $completedId) {
            $ticket->forceFill([
                'assigned_user' => UserFactory::new()->inDepartment($ticket->department)->create()->id,
            ])->save();
        }

        if ($ticket->assigned_user === null) {
            return;
        }

        $assignee = User::query()->find($ticket->assigned_user);

        TicketDateActivityFactory::new()->for($ticket, 'ticket')->assigned($assignee)->create();

        if ($ticket->status_id === $completedId) {
            TicketDateActivityFactory::new()->for($ticket, 'ticket')->completed($assignee)->create();
        }
    }

    private function createDetails(Ticket $ticket): void
    {
        $isEngineering = Department::query()->whereKey($ticket->department)->value('department') === 'Engineering';

        if ($isEngineering) {
            TicketEngineeringFactory::new()->state(['ticket_id' => $ticket->id])->create();

            return;
        }

        TicketITFactory::new()->state(['ticket_id' => $ticket->id])->create();
        DeviceFactory::new()->state(['ticket_id' => $ticket->id])->create();
    }
}
