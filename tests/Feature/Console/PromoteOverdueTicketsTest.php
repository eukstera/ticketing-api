<?php

namespace Tests\Feature\Console;

use App\Models\Ticket;
use App\Models\User;
use Database\Factories\PriorityFactory;
use Illuminate\Support\Carbon;
use Tests\Concerns\SeedsReferenceData;
use Tests\TestCase;

class PromoteOverdueTicketsTest extends TestCase
{
    use SeedsReferenceData;

    protected function setUp(): void
    {
        parent::setUp();

        // A Wednesday, so "N days ago" never depends on the weekday the suite runs on.
        $this->travelTo('2026-05-20 12:00:00');
    }

    private function statusLabel(Ticket $ticket): string
    {
        return $ticket->fresh()->ticketStatus->label;
    }

    private function ticketRequestedAt(Carbon $moment, int $slaDays = 2): Ticket
    {
        $priority = PriorityFactory::new()->slaDays($slaDays)->create(['department_id' => $this->itDepartment()->id]);

        return Ticket::factory()->requestedAt($moment)->create(['priority_id' => $priority->id]);
    }

    public function test_weekends_do_not_count_towards_the_sla(): void
    {
        $this->seedReferenceData();
        // Friday request, 1 working day: due at the start of Monday, not on Saturday.
        $friday = $this->ticketRequestedAt(Carbon::parse('2026-05-15 16:00:00'), 1);

        $this->travelTo('2026-05-16 12:00:00');
        $this->artisan('tickets:promote-overdue')->assertSuccessful();
        $this->assertSame('Open', $this->statusLabel($friday));

        $this->travelTo('2026-05-17 23:00:00');
        $this->artisan('tickets:promote-overdue')->assertSuccessful();
        $this->assertSame('Open', $this->statusLabel($friday));

        $this->travelTo('2026-05-18 00:05:00');
        $this->artisan('tickets:promote-overdue')->assertSuccessful();
        $this->assertSame('Overdue', $this->statusLabel($friday));
    }

    public function test_a_weekend_request_starts_counting_on_monday(): void
    {
        $this->seedReferenceData();
        // Saturday request, 1 working day: counting starts Monday, due at the start of Tuesday.
        $saturday = $this->ticketRequestedAt(Carbon::parse('2026-05-16 10:00:00'), 1);

        $this->travelTo('2026-05-18 20:00:00');
        $this->artisan('tickets:promote-overdue')->assertSuccessful();
        $this->assertSame('Open', $this->statusLabel($saturday));

        $this->travelTo('2026-05-19 00:05:00');
        $this->artisan('tickets:promote-overdue')->assertSuccessful();
        $this->assertSame('Overdue', $this->statusLabel($saturday));
    }

    public function test_open_ticket_past_its_sla_becomes_overdue(): void
    {
        $this->seedReferenceData();
        $ticket = $this->ticketRequestedAt(now()->subDays(3));

        $this->artisan('tickets:promote-overdue')->assertSuccessful();

        $this->assertSame('Overdue', $this->statusLabel($ticket));
    }

    public function test_in_progress_ticket_past_its_sla_becomes_overdue(): void
    {
        $this->seedReferenceData();
        $priority = PriorityFactory::new()->slaDays(1)->create(['department_id' => 1]);
        $user = User::factory()->inDepartment($this->itDepartment())->create();
        $ticket = Ticket::factory()->assigned($user)->requestedAt(now()->subDays(5))->create(['priority_id' => $priority->id]);

        $this->artisan('tickets:promote-overdue')->assertSuccessful();

        $this->assertSame('Overdue', $this->statusLabel($ticket));
    }

    public function test_ticket_within_its_sla_is_left_alone(): void
    {
        $this->seedReferenceData();
        $ticket = $this->ticketRequestedAt(now()->subHours(2));

        $this->artisan('tickets:promote-overdue')->assertSuccessful();

        $this->assertSame('Open', $this->statusLabel($ticket));
    }

    public function test_cutoff_is_whole_calendar_days(): void
    {
        $this->seedReferenceData();
        $overdue = $this->ticketRequestedAt(now()->subDays(2)->endOfDay());
        $notYet = $this->ticketRequestedAt(now()->subDays(1)->startOfDay());

        $this->artisan('tickets:promote-overdue')->assertSuccessful();

        $this->assertSame('Overdue', $this->statusLabel($overdue));
        $this->assertSame('Open', $this->statusLabel($notYet));
    }

    public function test_completed_and_already_overdue_tickets_are_not_touched(): void
    {
        $this->seedReferenceData();
        $priority = PriorityFactory::new()->slaDays(1)->create(['department_id' => 1]);
        $completed = Ticket::factory()->completed()->requestedAt(now()->subDays(9))->create(['priority_id' => $priority->id]);
        $overdue = Ticket::factory()->overdue()->requestedAt(now()->subDays(9))->create(['priority_id' => $priority->id]);
        $stamp = $overdue->fresh()->updated_at;

        $this->travel(1)->hours();
        $this->artisan('tickets:promote-overdue')->assertSuccessful();

        $this->assertSame('Completed', $this->statusLabel($completed));
        $this->assertTrue($stamp->equalTo($overdue->fresh()->updated_at));
    }

    public function test_sla_is_applied_per_priority(): void
    {
        $this->seedReferenceData();
        $short = $this->ticketRequestedAt(now()->subDays(3), 2);
        $long = $this->ticketRequestedAt(now()->subDays(3), 10);

        $this->artisan('tickets:promote-overdue')->assertSuccessful();

        $this->assertSame('Overdue', $this->statusLabel($short));
        $this->assertSame('Open', $this->statusLabel($long));
    }

    public function test_command_fails_clearly_without_status_rows(): void
    {
        $this->artisan('tickets:promote-overdue')
            ->expectsOutputToContain('Status lookup rows')
            ->assertFailed();
    }

    public function test_command_is_scheduled_every_five_minutes(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('tickets:promote-overdue')
            ->assertSuccessful();
    }
}
