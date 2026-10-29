<?php

namespace Tests\Feature\Tickets;

use App\Models\Status;
use App\Models\Ticket;
use App\Models\TicketDateActivity;
use App\Models\User;
use App\Services\JobOrderData;
use Database\Factories\TicketFactory;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Testing\TestResponse;
use Tests\ApiTestCase;

class BlankJobOrderTest extends ApiTestCase
{
    private function reserve(): TestResponse
    {
        return $this->postJson('/api/tickets/blank-job-order');
    }

    private function blankTicket(): Ticket
    {
        $id = $this->reserve()->assertCreated()->json('result.ticket.id');

        return Ticket::findOrFail($id);
    }

    private function statusLabel(Ticket $ticket): string
    {
        return $ticket->fresh()->ticketStatus->label;
    }

    private function fillPayload(array $overrides = []): array
    {
        return array_merge([
            'requested_at' => now()->subDay()->format('Y-m-d H:i'),
            'requestor' => 'Juan Dela Cruz',
            'requestor_email' => '  Juan@Hospital.test ',
            'area_supervisor' => 'Maria Santos',
            'type_of_concern' => 'Software',
            'name_of_area' => 'Pharmacy',
            'remarks' => 'The billing app freezes when saving.',
            'priority' => 2,
        ], $overrides);
    }

    private function encoder(): User
    {
        return User::factory()->inDepartment($this->itDepartment())->encoder()->create();
    }

    // ------------------------------------------------------------- reserve

    public function test_requires_authentication(): void
    {
        $this->reserve()->assertUnauthorized();
    }

    public function test_it_staff_reserve_the_next_ticket_number_as_a_blank_ticket(): void
    {
        $user = $this->actingAsUser($this->itUser());
        $before = $this->openItTicket();

        $response = $this->reserve()
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('result.ticket.status', 'Blank');

        $ticket = Ticket::findOrFail($response->json('result.ticket.id'));
        $response->assertJsonPath('result.ticket.ticket_number', $ticket->ticket_number)
            ->assertJsonPath('result.job_order_url', "/api/tickets/{$ticket->id}/job-order?format=pdf");

        $this->assertSame('Blank', $this->statusLabel($ticket));
        $this->assertSame($this->itDepartment()->id, (int) $ticket->department);
        $this->assertSame($user->id, (int) $ticket->assigned_user);
        $response->assertJsonPath('result.assignee.id', $user->id)->assertJsonPath('result.ticket.assigned_user', $user->id);
        $this->assertDatabaseHas('ticket_date_activities', ['ticket_id' => $ticket->id, 'date_category_id' => 2, 'user_id' => $user->id]);
        $this->assertDatabaseHas('tickets_it', ['ticket_id' => $ticket->id]);
        $this->assertDatabaseHas('ticket_date_activities', ['ticket_id' => $ticket->id, 'date_category_id' => 9, 'user_id' => $user->id]);
        $this->assertDatabaseMissing('ticket_date_activities', ['ticket_id' => $ticket->id, 'date_category_id' => 1]);

        $this->assertSame(
            (int) explode('-', $before->ticket_number)[1] + 1,
            (int) explode('-', $ticket->ticket_number)[1],
            'a blank job order takes the next number in the normal sequence',
        );
    }

    public function test_every_print_reserves_exactly_one_new_number(): void
    {
        $this->actingAsUser($this->itUser());

        $first = $this->blankTicket();
        $second = $this->blankTicket();
        $normal = $this->openItTicket();

        $numbers = array_map(fn (Ticket $t) => (int) explode('-', $t->ticket_number)[1], [$first, $second]);
        $this->assertSame($numbers[0] + 1, $numbers[1]);
        $this->assertCount(3, array_unique([$first->ticket_number, $second->ticket_number, $normal->ticket_number]));
    }

    public function test_department_heads_can_print_but_encoders_and_other_departments_cannot(): void
    {
        $this->actingAsAdmin();
        $this->reserve()->assertCreated();

        $this->actingAsUser($this->encoder());
        $this->reserve()->assertForbidden()->assertJsonPath('message', 'Encoders have read-only access.');

        $this->actingAsUser($this->engineeringUser());
        $this->reserve()->assertForbidden()->assertJsonPath('message', 'Blank job orders can only be printed by the IT department.');

        $this->assertSame(1, Ticket::query()->count());
    }

    public function test_the_blank_form_is_a_pdf_and_reprints_while_blank(): void
    {
        $this->actingAsUser($this->itUser());
        $ticket = $this->blankTicket();

        for ($i = 0; $i < 2; $i++) {
            $response = $this->get("/api/tickets/{$ticket->id}/job-order?format=pdf")->assertOk();
            $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
            $this->assertStringStartsWith('%PDF', file_get_contents($response->baseResponse->getFile()->getPathname()));
        }

        $this->get("/api/tickets/{$ticket->id}/job-order")->assertStatus(409)
            ->assertJsonPath('message', 'A blank job order is only available as a PDF.');
    }

    public function test_the_blank_form_shows_only_the_ticket_number(): void
    {
        $this->actingAsUser($this->itUser());
        $ticket = $this->blankTicket();

        $data = JobOrderData::fromTicket($ticket->fresh());

        $this->assertSame(JobOrderData::displayNumber($ticket), $data->number);
        $this->assertNull($data->priority);
        $this->assertNull($data->requesterName);
        $this->assertNull($data->requestedDate);
        $this->assertNull($data->division);
        $this->assertSame([], $data->detailLines);
    }

    // ------------------------------------------------------------- lists

    public function test_blank_tickets_show_in_lists_and_void_ones_only_when_asked_for(): void
    {
        $user = $this->actingAsUser($this->itUser());
        $blank = $this->blankTicket();
        $voided = $this->blankTicket();
        $this->postJson("/api/tickets/{$voided->id}/void", ['reason' => 'Spoiled form'])->assertOk();
        $normal = $this->openItTicket();

        $default = $this->getJson('/api/getDepartmentTickets')->assertOk();
        $ids = array_column($default->json('result.ticket.data'), 'id');
        $this->assertContains($blank->id, $ids);
        $this->assertContains($normal->id, $ids);
        $this->assertNotContains($voided->id, $ids);
        $this->assertSame('Blank', collect($default->json('result.ticket.data'))->firstWhere('id', $blank->id)['status']);

        $counts = collect($default->json('result.status_counts'));
        $this->assertSame(2, $counts->firstWhere('label', 'All')['count']);
        $this->assertSame(1, $counts->firstWhere('label', 'Void')['count']);
        $this->assertSame(1, $counts->firstWhere('label', 'Blank')['count']);

        $voidId = Status::where('label', 'Void')->value('id');
        $filtered = $this->getJson('/api/getDepartmentTickets?status_id='.$voidId)->assertOk();
        $this->assertSame([$voided->id], array_column($filtered->json('result.ticket.data'), 'id'));
        $this->assertSame('Spoiled form', $filtered->json('result.ticket.data.0.void_activity.remarks'));
        $this->assertNotEmpty($filtered->json('result.ticket.data.0.void_activity.user'));
        $this->assertNull(collect($default->json('result.ticket.data'))->firstWhere('id', $blank->id)['void_activity']);
    }

    public function test_blank_and_void_tickets_stay_out_of_the_dashboard_totals_and_the_alarm_feed(): void
    {
        $this->actingAsUser($this->itUser());
        $blank = $this->blankTicket();
        $voided = $this->blankTicket();
        $this->postJson("/api/tickets/{$voided->id}/void", ['reason' => 'Spoiled form'])->assertOk();
        $this->openItTicket();

        $this->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('result.overview.blank', 1)
            ->assertJsonPath('result.overview.void', 1)
            ->assertJsonPath('result.todays_ticket.total', 1);

        $feed = $this->getJson('/api/tickets/new?after_id=0')->assertOk();
        $this->assertNotContains($blank->id, array_column($feed->json('result.tickets'), 'id'));
        $this->assertNotContains($voided->id, array_column($feed->json('result.tickets'), 'id'));
    }

    public function test_void_job_orders_count_in_the_consolidated_report_but_unfilled_blanks_do_not(): void
    {
        $encoder = $this->encoder();
        $this->actingAsUser($this->itUser());
        $this->blankTicket();
        $voided = $this->blankTicket();
        $this->postJson("/api/tickets/{$voided->id}/void", ['reason' => 'Spoiled form'])->assertOk();
        $this->openItTicket();

        $this->actingAsUser($encoder);
        $range = http_build_query(['from' => now()->subDay()->toDateString(), 'to' => now()->addDay()->toDateString()]);
        $report = $this->getJson("/api/reports/consolidated?{$range}")->assertOk();

        $this->assertSame(1, $report->json('result.summary.voided'));
        $labels = array_column($report->json('result.breakdowns.by_status'), 'label');
        $this->assertNotContains('Blank', $labels);
        $this->assertContains('Void', $labels);
        $options = array_column($this->getJson('/api/reports/consolidated/options')->json('result.statuses'), 'name');
        $this->assertNotContains('Blank', $options);
        $this->assertContains('Void', $options);
    }

    // ------------------------------------------------------------- fill in

    public function test_the_owner_fills_it_in_and_it_becomes_a_normal_in_progress_ticket_they_keep(): void
    {
        $user = $this->actingAsUser($this->itUser());
        $ticket = $this->blankTicket();
        $number = $ticket->ticket_number;
        $requestedAt = now()->subDays(2)->setTime(9, 30);

        $this->patchJson("/api/tickets/{$ticket->id}/fill-blank", $this->fillPayload(['requested_at' => $requestedAt->format('Y-m-d H:i')]))
            ->assertOk()
            ->assertJsonPath('result.ticket.ticket_number', $number);

        $fresh = $ticket->fresh();
        $this->assertSame('In-progress', $this->statusLabel($ticket));
        $this->assertSame($user->id, (int) $fresh->assigned_user);
        $this->assertSame(1, TicketDateActivity::where('ticket_id', $ticket->id)->where('date_category_id', 2)->count());
        $this->assertSame(2, (int) $fresh->priority_id);
        $this->assertSame('juan@hospital.test', $fresh->requester_email);
        $this->assertDatabaseHas('tickets_it', ['ticket_id' => $ticket->id, 'name_of_area' => 'Pharmacy', 'area_supervisor' => 'Maria Santos']);
        $this->assertDatabaseHas('ticket_date_activities', ['ticket_id' => $ticket->id, 'date_category_id' => 1, 'requestor' => 'Juan Dela Cruz']);
        $this->assertDatabaseHas('ticket_date_activities', ['ticket_id' => $ticket->id, 'date_category_id' => 10, 'user_id' => $user->id]);

        $detail = $this->getJson("/api/tickets/{$ticket->id}")->assertOk()
            ->assertJsonPath('result.ticket.status.label', 'In-progress')
            ->assertJsonPath('result.ticket.assignee.id', $user->id)
            ->assertJsonPath('result.ticket.details.name_of_area', 'Pharmacy')
            ->assertJsonPath('result.ticket.requester.name', 'Juan Dela Cruz');

        // The clock runs from the paper's request time, not from the earlier print time.
        $this->assertSame($requestedAt->toIso8601String(), $detail->json('result.ticket.clock_started_at'));
        $this->assertNotNull($detail->json('result.ticket.blank_job_order.printed_at'));
        $this->assertNotNull($detail->json('result.ticket.blank_job_order.filled_in_at'));
        $this->assertSame('Software', $detail->json('result.ticket.details.type_of_concern'));
    }

    public function test_a_blank_row_in_the_lists_shows_its_owner(): void
    {
        $user = $this->actingAsUser($this->itUser());
        $ticket = $this->blankTicket();

        $row = collect($this->getJson('/api/getDepartmentTickets')->assertOk()->json('result.ticket.data'))->firstWhere('id', $ticket->id);
        $this->assertNotEmpty($row['assigned_activity']['user']);

        $mine = array_column($this->getJson('/api/getMyTickets')->assertOk()->json('result.ticket.data'), 'id');
        $this->assertContains($ticket->id, $mine);
        $this->assertSame($user->id, (int) $ticket->fresh()->assigned_user);
    }

    public function test_a_department_head_can_fill_in_for_the_owner_and_hand_it_to_another_member(): void
    {
        $owner = $this->actingAsUser($this->itUser());
        $ticket = $this->blankTicket();
        $other = $this->itUser();

        $this->actingAsAdmin();
        $this->patchJson("/api/tickets/{$ticket->id}/fill-blank", $this->fillPayload(['assigned_user' => $other->id]))->assertOk();

        $this->assertSame('In-progress', $this->statusLabel($ticket));
        $this->assertSame($other->id, (int) $ticket->fresh()->assigned_user);
        $this->assertNotSame($owner->id, (int) $ticket->fresh()->assigned_user);
        $this->assertSame(1, TicketDateActivity::where('ticket_id', $ticket->id)->where('date_category_id', 2)->count());
        $this->assertDatabaseHas('ticket_date_activities', ['ticket_id' => $ticket->id, 'date_category_id' => 2, 'user_id' => $other->id]);
    }

    public function test_the_owner_cannot_hand_it_to_someone_else_while_filling_in(): void
    {
        $this->actingAsUser($this->itUser());
        $ticket = $this->blankTicket();

        $this->patchJson("/api/tickets/{$ticket->id}/fill-blank", $this->fillPayload(['assigned_user' => $this->itUser()->id]))
            ->assertForbidden();

        $this->assertSame('Blank', $this->statusLabel($ticket));
    }

    public function test_other_staff_cannot_fill_in_void_or_reject_someone_elses_blank_ticket(): void
    {
        $this->actingAsUser($this->itUser());
        $ticket = $this->blankTicket();

        $this->actingAsUser($this->itUser());
        $this->patchJson("/api/tickets/{$ticket->id}/fill-blank", $this->fillPayload())->assertForbidden()
            ->assertJsonPath('message', 'Only the member who printed this blank job order can fill it in.');
        $this->postJson("/api/tickets/{$ticket->id}/void", ['reason' => 'Not mine to void'])->assertForbidden()
            ->assertJsonPath('message', 'Only the member who printed this blank job order can void it.');
        $this->postJson("/api/tickets/{$ticket->id}/false-alarm", ['reason' => 'Not mine to reject'])->assertForbidden()
            ->assertJsonPath('message', 'Only the member who printed this blank job order can mark it as a false alarm.');

        $this->assertSame('Blank', $this->statusLabel($ticket));
    }

    public function test_the_fill_in_fields_are_validated(): void
    {
        $this->actingAsUser($this->itUser());
        $ticket = $this->blankTicket();

        $this->patchJson("/api/tickets/{$ticket->id}/fill-blank", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['requested_at', 'requestor', 'area_supervisor', 'type_of_concern', 'name_of_area', 'remarks', 'priority']);

        $this->patchJson("/api/tickets/{$ticket->id}/fill-blank", $this->fillPayload(['requested_at' => now()->addDay()->format('Y-m-d H:i')]))
            ->assertUnprocessable()->assertJsonValidationErrors(['requested_at']);
        $this->patchJson("/api/tickets/{$ticket->id}/fill-blank", $this->fillPayload(['priority' => 5]))
            ->assertUnprocessable()->assertJsonValidationErrors(['priority']);
        $this->patchJson("/api/tickets/{$ticket->id}/fill-blank", $this->fillPayload(['type_of_concern' => 'Hardware']))
            ->assertUnprocessable()->assertJsonValidationErrors(['devices']);
        $this->patchJson("/api/tickets/{$ticket->id}/fill-blank", $this->fillPayload(['requestor_email' => 'nope']))
            ->assertUnprocessable()->assertJsonValidationErrors(['requestor_email']);

        $this->actingAsAdmin();
        $this->patchJson("/api/tickets/{$ticket->id}/fill-blank", $this->fillPayload(['assigned_user' => $this->engineeringUser()->id]))
            ->assertUnprocessable()->assertJsonValidationErrors(['assigned_user']);

        $this->assertSame('Blank', $this->statusLabel($ticket));
    }

    public function test_only_a_blank_ticket_can_be_filled_in_and_not_by_other_departments_or_encoders(): void
    {
        $owner = $this->actingAsUser($this->itUser());
        $normal = TicketFactory::new()->forDepartment($this->itDepartment())->assigned($owner)->create();
        $this->patchJson("/api/tickets/{$normal->id}/fill-blank", $this->fillPayload())->assertStatus(409)
            ->assertJsonPath('message', 'Only a blank job order can be filled in.');

        $this->actingAsUser($this->itUser());
        $this->patchJson("/api/tickets/{$normal->id}/fill-blank", $this->fillPayload())->assertForbidden();

        $this->actingAsAdmin();
        $this->patchJson("/api/tickets/{$normal->id}/fill-blank", $this->fillPayload())->assertStatus(409);

        $this->actingAsUser($owner);
        $blank = $this->blankTicket();
        $this->patchJson("/api/tickets/{$blank->id}/fill-blank", $this->fillPayload())->assertOk();
        $this->patchJson("/api/tickets/{$blank->id}/fill-blank", $this->fillPayload())->assertStatus(409);
        $this->actingAsAdmin();
        $this->patchJson("/api/tickets/{$blank->id}/fill-blank", $this->fillPayload())->assertStatus(409);

        $this->actingAsUser($owner);
        $other = $this->blankTicket();
        $this->actingAsUser($this->engineeringUser());
        $this->patchJson("/api/tickets/{$other->id}/fill-blank", $this->fillPayload())->assertForbidden();

        $this->actingAsUser($this->encoder());
        $this->patchJson("/api/tickets/{$other->id}/fill-blank", $this->fillPayload())->assertForbidden()
            ->assertJsonPath('message', 'Encoders have read-only access.');
    }

    // ------------------------------------------------------------- void

    public function test_a_blank_ticket_can_be_voided_and_keeps_its_number(): void
    {
        $user = $this->actingAsUser($this->itUser());
        $ticket = $this->blankTicket();

        $this->postJson("/api/tickets/{$ticket->id}/void", ['reason' => '  Form was spoiled  '])
            ->assertOk()
            ->assertJsonPath('result.void.reason', 'Form was spoiled')
            ->assertJsonPath('result.void.voided_by.id', $user->id);

        $this->assertSame('Void', $this->statusLabel($ticket));
        $this->assertDatabaseHas('ticket_date_activities', ['ticket_id' => $ticket->id, 'date_category_id' => 11, 'remarks' => 'Form was spoiled']);

        $detail = $this->getJson("/api/tickets/{$ticket->id}")->assertOk()
            ->assertJsonPath('result.ticket.status.label', 'Void')
            ->assertJsonPath('result.ticket.void.reason', 'Form was spoiled')
            ->assertJsonPath('result.ticket.is_overdue', false);
        $this->assertNotNull($detail->json('result.ticket.void.voided_at'));

        $this->get("/api/tickets/{$ticket->id}/job-order?format=pdf")->assertStatus(409);

        $next = $this->openItTicket();
        $this->assertNotSame($ticket->ticket_number, $next->ticket_number);
    }

    public function test_only_a_blank_ticket_can_be_voided_and_the_reason_is_validated(): void
    {
        $owner = $this->actingAsUser($this->itUser());
        $normal = TicketFactory::new()->forDepartment($this->itDepartment())->assigned($owner)->create();
        $this->actingAsAdmin();
        $this->postJson("/api/tickets/{$normal->id}/void", ['reason' => 'Not needed'])->assertStatus(409)
            ->assertJsonPath('message', 'Only a blank job order can be voided.');

        $this->actingAsUser($owner);
        $blank = $this->blankTicket();
        foreach ([[], ['reason' => ''], ['reason' => 'abcd'], ['reason' => str_repeat('a', 256)]] as $body) {
            $this->postJson("/api/tickets/{$blank->id}/void", $body)->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        }

        $this->postJson("/api/tickets/{$blank->id}/void", ['reason' => str_repeat('a', 255)])->assertOk();
        $this->postJson("/api/tickets/{$blank->id}/void", ['reason' => 'Again please'])->assertStatus(409)
            ->assertJsonPath('message', 'This ticket is already void.');
    }

    public function test_void_is_for_the_owner_and_heads_and_not_for_other_departments_or_encoders(): void
    {
        $this->actingAsUser($this->itUser());
        $ticket = $this->blankTicket();

        $this->actingAsUser($this->engineeringUser());
        $this->postJson("/api/tickets/{$ticket->id}/void", ['reason' => 'Not mine'])->assertForbidden();

        $this->actingAsUser($this->encoder());
        $this->postJson("/api/tickets/{$ticket->id}/void", ['reason' => 'Not mine'])->assertForbidden()
            ->assertJsonPath('message', 'Encoders have read-only access.');

        $this->actingAsAdmin();
        $this->postJson("/api/tickets/{$ticket->id}/void", ['reason' => 'Head voids it'])->assertOk();
    }

    // ------------------------------------------------------------- other flows

    public function test_a_blank_ticket_can_be_rejected_as_a_false_alarm_and_reopens_to_blank_still_owned(): void
    {
        $owner = $this->actingAsUser($this->itUser());
        $ticket = $this->blankTicket();

        $this->postJson("/api/tickets/{$ticket->id}/false-alarm", ['reason' => 'Printed by mistake'])->assertOk();
        $this->assertSame('False Alarm', $this->statusLabel($ticket));

        $this->actingAsAdmin();
        $this->postJson("/api/tickets/{$ticket->id}/reopen", [])->assertOk();
        $this->assertSame('Blank', $this->statusLabel($ticket));
        $this->assertSame($owner->id, (int) $ticket->fresh()->assigned_user);
        $this->assertDatabaseHas('ticket_date_activities', ['ticket_id' => $ticket->id, 'date_category_id' => 2, 'user_id' => $owner->id]);

        $this->actingAsUser($owner);
        $paperTime = now()->subDays(3)->setTime(10, 0);
        $this->patchJson("/api/tickets/{$ticket->id}/fill-blank", $this->fillPayload(['requested_at' => $paperTime->format('Y-m-d H:i')]))->assertOk();

        $this->getJson("/api/tickets/{$ticket->id}")->assertOk()
            ->assertJsonPath('result.ticket.clock_started_at', $paperTime->toIso8601String())
            ->assertJsonPath('result.ticket.assignee.id', $owner->id);
    }

    public function test_a_blank_ticket_cannot_be_reassigned_or_completed_even_by_its_owner(): void
    {
        $head = $this->actingAsAdmin();
        $ticket = $this->blankTicket();

        $this->postJson("/api/tickets/{$ticket->id}/assign", ['user_id' => $head->id])->assertStatus(409);
        $this->postJson("/api/tickets/{$ticket->id}/complete", ['notes' => 'Replaced the faulty power supply and tested.'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'A blank or void job order cannot be completed. Fill it in first.');
        $this->assertSame('Blank', $this->statusLabel($ticket));
    }

    public function test_a_blank_ticket_never_becomes_overdue(): void
    {
        $this->actingAsUser($this->itUser());
        $ticket = $this->blankTicket();
        TicketDateActivity::query()->where('ticket_id', $ticket->id)->update(['created_at' => now()->subMonths(2)]);

        $this->artisan('tickets:promote-overdue')->assertSuccessful();

        $this->assertSame('Blank', $this->statusLabel($ticket));
        $this->getJson("/api/tickets/{$ticket->id}")->assertJsonPath('result.ticket.is_overdue', false)
            ->assertJsonPath('result.ticket.clock_started_at', null);
    }

    public function test_the_seeder_adds_the_new_statuses_and_categories_idempotently(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(ReferenceDataSeeder::class);

        $this->assertSame(1, Status::where('label', 'Blank')->count());
        $this->assertSame(1, Status::where('label', 'Void')->count());
        $this->assertDatabaseHas('date_categories', ['category' => 'Blank JO Printed Date']);
        $this->assertDatabaseHas('date_categories', ['category' => 'Filled In Date']);
        $this->assertDatabaseHas('date_categories', ['category' => 'Void Date']);
    }

    public function test_requesting_the_normal_tickets_still_work_next_to_blank_ones(): void
    {
        $this->actingAsUser($this->itUser());
        $this->blankTicket();
        $ticket = TicketFactory::new()->forDepartment($this->itDepartment())->open()->create();

        $this->getJson("/api/tickets/{$ticket->id}")->assertOk();
        $this->assertSame(2, Ticket::query()->count());
    }
}
