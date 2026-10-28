<?php

namespace Tests\Feature\Tickets;

use App\Models\Status;
use App\Models\Ticket;
use App\Models\TicketDateActivity;
use App\Models\TicketIT;
use Database\Factories\TicketFactory;
use Database\Seeders\ReferenceDataSeeder;
use Tests\ApiTestCase;

class FalseAlarmTest extends ApiTestCase
{
    private const REASON = 'Duplicate of an existing request';

    private function openTicket(): Ticket
    {
        return TicketFactory::new()->forDepartment($this->itDepartment())->open()->create();
    }

    private function reject(Ticket $ticket, array $body = ['reason' => self::REASON])
    {
        return $this->postJson("/api/tickets/{$ticket->id}/false-alarm", $body);
    }

    private function statusLabel(Ticket $ticket): string
    {
        return $ticket->fresh()->ticketStatus->label;
    }

    public function test_requires_authentication(): void
    {
        $this->reject($this->openTicket())->assertUnauthorized();
    }

    public function test_handling_department_member_can_mark_an_open_ticket(): void
    {
        $user = $this->actingAsUser($this->itUser());
        $ticket = $this->openTicket();

        $response = $this->reject($ticket)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Ticket marked as false alarm')
            ->assertJsonPath('result.false_alarm.reason', self::REASON)
            ->assertJsonPath('result.false_alarm.marked_by.id', $user->id);

        $this->assertNotEmpty($response->json('result.false_alarm.marked_at'));
        $this->assertSame('False Alarm', $this->statusLabel($ticket));
        $response->assertJsonPath('result.ticket.status_id', $ticket->fresh()->status_id);
        $this->assertDatabaseHas('ticket_date_activities', [
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'remarks' => self::REASON,
        ]);
    }

    public function test_reason_is_trimmed(): void
    {
        $this->actingAsUser($this->itUser());
        $ticket = $this->openTicket();

        $this->reject($ticket, ['reason' => '   Wrong department   '])->assertOk()
            ->assertJsonPath('result.false_alarm.reason', 'Wrong department');
    }

    public function test_an_untaken_overdue_ticket_can_be_marked(): void
    {
        $this->actingAsUser($this->itUser());
        $ticket = TicketFactory::new()->forDepartment($this->itDepartment())->overdue()->create(['assigned_user' => null]);

        $this->reject($ticket)->assertOk();

        $this->assertSame('False Alarm', $this->statusLabel($ticket));
    }

    public function test_other_department_staff_are_forbidden(): void
    {
        $this->actingAsUser($this->engineeringUser());
        $ticket = $this->openTicket();

        $this->reject($ticket)
            ->assertForbidden()
            ->assertJsonPath('message', 'Only the assignee or members of the handling department can mark a ticket as a false alarm.');

        $this->assertSame('Open', $this->statusLabel($ticket));
    }

    public function test_department_head_can_mark_a_ticket(): void
    {
        $this->actingAsAdmin();
        $ticket = $this->openTicket();

        $this->reject($ticket)->assertOk();
    }

    public function test_completed_and_pending_confirmation_tickets_are_refused(): void
    {
        $this->actingAsUser($this->itUser());
        $assignee = $this->itUser();

        foreach ([
            TicketFactory::new()->forDepartment($this->itDepartment())->completed($assignee)->create(),
            TicketFactory::new()->forDepartment($this->itDepartment())->assigned($assignee)->create(['status_id' => Status::where('label', 'Pending Confirmation')->value('id')]),
        ] as $ticket) {
            $before = $ticket->fresh()->status_id;

            $this->reject($ticket)
                ->assertStatus(409)
                ->assertJsonPath('message', 'Only open or in-progress tickets can be marked as a false alarm.');

            $this->assertSame($before, $ticket->fresh()->status_id);
        }
    }

    public function test_a_taken_in_progress_ticket_can_be_marked_by_its_assignee_a_colleague_or_a_head(): void
    {
        $assignee = $this->itUser();

        foreach ([$assignee, $this->itUser(), null] as $actor) {
            $actor === null ? $this->actingAsAdmin() : $this->actingAsUser($actor);
            $ticket = TicketFactory::new()->forDepartment($this->itDepartment())->assigned($assignee)->create();

            $this->reject($ticket)->assertOk()->assertJsonPath('result.false_alarm.reason', self::REASON);

            $fresh = $ticket->fresh();
            $this->assertSame('False Alarm', $this->statusLabel($ticket));
            $this->assertSame($assignee->id, (int) $fresh->assigned_user);
            $this->assertDatabaseHas('ticket_date_activities', ['ticket_id' => $ticket->id, 'date_category_id' => 2, 'user_id' => $assignee->id]);
        }
    }

    public function test_a_taken_overdue_ticket_can_be_marked(): void
    {
        $this->actingAsUser($this->itUser());
        $ticket = TicketFactory::new()->forDepartment($this->itDepartment())->assigned($this->itUser())->overdue()->create();

        $this->reject($ticket)->assertOk();
    }

    public function test_marking_twice_is_a_conflict_and_records_one_entry(): void
    {
        $this->actingAsUser($this->itUser());
        $ticket = $this->openTicket();

        $this->reject($ticket)->assertOk();
        $this->reject($ticket)
            ->assertStatus(409)
            ->assertJsonPath('message', 'This ticket has already been marked as a false alarm.');

        $this->assertSame(1, TicketDateActivity::query()->where('ticket_id', $ticket->id)->whereNotNull('remarks')->count());
    }

    public function test_a_ticket_completed_before_the_click_is_refused(): void
    {
        $this->actingAsUser($this->itUser());
        $ticket = $this->openTicket();
        $ticket->update(['status_id' => Status::where('label', 'Completed')->value('id')]);

        $this->reject($ticket)->assertStatus(409);
    }

    public function test_reason_is_validated(): void
    {
        $this->actingAsUser($this->itUser());
        $ticket = $this->openTicket();

        foreach ([[], ['reason' => ''], ['reason' => 'ab'], ['reason' => str_repeat('a', 501)], ['reason' => ['x']]] as $body) {
            $this->reject($ticket, $body)->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        }

        $this->reject($ticket, ['reason' => str_repeat('a', 500)])->assertOk();
    }

    public function test_unknown_ticket_is_not_found(): void
    {
        $this->actingAsUser($this->itUser());

        $this->postJson('/api/tickets/9999/false-alarm', ['reason' => self::REASON])->assertNotFound();
    }

    public function test_false_alarm_tickets_cannot_be_assigned_or_completed(): void
    {
        $this->actingAsUser($this->itUser());
        $ticket = $this->openTicket();
        $this->reject($ticket)->assertOk();

        $this->postJson("/api/tickets/{$ticket->id}/assign", ['user_id' => $this->itUser()->id])
            ->assertForbidden();
        $this->actingAsAdmin();
        $this->postJson("/api/tickets/{$ticket->id}/assign", ['user_id' => $this->itUser()->id])
            ->assertStatus(409)
            ->assertJsonPath('message', 'A false-alarm ticket cannot be assigned.');
        $ticket->update(['assigned_user' => $this->actingAsAdmin()->id]);
        $this->postJson("/api/tickets/{$ticket->id}/complete", ['notes' => 'Replaced the faulty power supply and tested.'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'A false-alarm ticket cannot be completed.');
    }

    public function test_detail_shows_the_reason_who_and_when_and_is_not_overdue(): void
    {
        $user = $this->actingAsUser($this->itUser());
        $ticket = TicketFactory::new()->forDepartment($this->itDepartment())->open()->requestedAt(now()->subDays(30))->create();
        $this->reject($ticket)->assertOk();

        $response = $this->getJson("/api/tickets/{$ticket->id}")->assertOk()
            ->assertJsonPath('result.ticket.status.label', 'False Alarm')
            ->assertJsonPath('result.ticket.is_overdue', false)
            ->assertJsonPath('result.ticket.false_alarm.reason', self::REASON)
            ->assertJsonPath('result.ticket.false_alarm.marked_by.id', $user->id);

        $entry = collect($response->json('result.ticket.timeline'))->firstWhere('category', 'False Alarm Date');
        $this->assertSame(self::REASON, $entry['remarks']);
        $this->assertNull(collect($response->json('result.ticket.timeline'))->firstWhere('category', 'Requested Date')['remarks']);
    }

    public function test_detail_has_a_null_false_alarm_for_normal_tickets(): void
    {
        $this->actingAsUser($this->itUser());
        $ticket = $this->openTicket();

        $this->getJson("/api/tickets/{$ticket->id}")->assertOk()->assertJsonPath('result.ticket.false_alarm', null);
    }

    public function test_list_counts_and_filters_include_the_new_status(): void
    {
        $this->actingAsUser($this->itUser());
        $rejected = $this->openTicket();
        $this->openTicket();
        $this->reject($rejected)->assertOk();
        $falseAlarmId = Status::where('label', 'False Alarm')->value('id');

        $counts = collect($this->getJson('/api/getDepartmentTickets')->assertOk()->json('result.status_counts'));

        $this->assertSame(2, $counts->firstWhere('label', 'All')['count']);
        $this->assertSame(1, $counts->firstWhere('label', 'False Alarm')['count']);
        $this->assertSame(1, $counts->firstWhere('label', 'Open')['count']);

        $byId = $this->getJson('/api/getDepartmentTickets?status_id='.$falseAlarmId)->assertOk();
        $this->assertSame([$rejected->id], array_column($byId->json('result.ticket.data'), 'id'));
        $this->assertSame('False Alarm', $byId->json('result.ticket.data.0.status'));
        $this->assertSame(self::REASON, $byId->json('result.ticket.data.0.false_alarm_activity.remarks'));

        $byLabel = $this->getJson('/api/getDepartmentTickets?status=False%20Alarm')->assertOk();
        $this->assertSame([$rejected->id], array_column($byLabel->json('result.ticket.data'), 'id'));
    }

    public function test_dashboard_counts_false_alarms_separately_and_keeps_percent_clean(): void
    {
        $this->actingAsUser($this->itUser());
        $rejected = $this->openTicket();
        $this->openTicket();
        $this->reject($rejected)->assertOk();

        $this->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('result.overview.false_alarm', 1)
            ->assertJsonPath('result.overview.open', 1)
            ->assertJsonPath('result.todays_ticket.total', 1);
    }

    public function test_track_shows_the_status_always_but_the_reason_only_with_the_name(): void
    {
        $this->actingAsUser($this->itUser());
        $ticket = $this->openTicket();
        TicketIT::query()->where('ticket_id', $ticket->id)->update(['remarks' => 'Printer jam']);
        TicketDateActivity::query()->where('ticket_id', $ticket->id)->where('date_category_id', 1)->update(['requestor' => 'Juan Dela Cruz']);
        $this->reject($ticket)->assertOk();

        $this->postJson('/api/track', ['ticket_number' => $ticket->ticket_number])
            ->assertOk()
            ->assertJsonPath('result.ticket.status', 'False Alarm')
            ->assertJsonMissingPath('result.ticket.false_alarm_reason');

        $this->postJson('/api/track', ['ticket_number' => $ticket->ticket_number, 'requestor' => 'Juan Dela Cruz'])
            ->assertOk()
            ->assertJsonPath('result.ticket.status', 'False Alarm')
            ->assertJsonPath('result.ticket.false_alarm_reason', self::REASON);
    }

    public function test_track_has_no_reason_field_for_other_statuses(): void
    {
        $ticket = $this->openTicket();
        TicketDateActivity::query()->where('ticket_id', $ticket->id)->where('date_category_id', 1)->update(['requestor' => 'Juan Dela Cruz']);

        $this->postJson('/api/track', ['ticket_number' => $ticket->ticket_number, 'requestor' => 'Juan Dela Cruz'])
            ->assertOk()
            ->assertJsonMissingPath('result.ticket.false_alarm_reason');
    }

    public function test_promote_overdue_leaves_false_alarms_alone(): void
    {
        $this->actingAsUser($this->itUser());
        $ticket = TicketFactory::new()->forDepartment($this->itDepartment())->open()->requestedAt(now()->subDays(60))->create();
        $this->reject($ticket)->assertOk();

        $this->artisan('tickets:promote-overdue')->assertSuccessful();

        $this->assertSame('False Alarm', $this->statusLabel($ticket));
    }

    public function test_new_ticket_feed_skips_false_alarms(): void
    {
        $this->actingAsUser($this->itUser());
        $kept = $this->openTicket();
        $rejected = $this->openTicket();
        $this->reject($rejected)->assertOk();

        $response = $this->getJson('/api/tickets/new?after_id=0')->assertOk();

        $this->assertSame([$kept->id], array_column($response->json('result.tickets'), 'id'));
    }

    public function test_reference_seeder_adds_the_status_and_category_idempotently(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(ReferenceDataSeeder::class);

        $this->assertDatabaseHas('status', ['label' => 'False Alarm']);
        $this->assertSame(1, Status::where('label', 'False Alarm')->count());
        $this->assertDatabaseHas('date_categories', ['category' => 'False Alarm Date']);
        $this->assertDatabaseCount('date_categories', 8);
        $this->assertSame(5, (int) Status::where('label', 'False Alarm')->value('id'));
    }
}
