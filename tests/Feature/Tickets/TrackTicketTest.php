<?php

namespace Tests\Feature\Tickets;

use App\Models\Ticket;
use App\Models\User;
use Database\Factories\NatureOfWorkFactory;
use Database\Factories\TicketDateActivityFactory;
use Database\Factories\TicketEngineeringFactory;
use Database\Factories\TicketFactory;
use Database\Factories\TicketITFactory;
use Illuminate\Support\Facades\RateLimiter;
use Tests\ApiTestCase;

class TrackTicketTest extends ApiTestCase
{
    private const URL = '/api/track';

    private function itTicket(string $requestor = 'Juan Dela Cruz'): Ticket
    {
        $ticket = TicketFactory::new()->withoutDetails()->forDepartment($this->itDepartment())
            ->requestedAt('2026-05-10 09:00:00')->create(['ticket_number' => '05-001-2026']);

        TicketITFactory::new()->state(['ticket_id' => $ticket->id])
            ->create(['name_of_area' => 'Pharmacy', 'remarks' => 'Printer jam']);
        TicketDateActivityFactory::new()->for($ticket, 'ticket')->requested()
            ->create(['requestor' => $requestor, 'created_at' => '2026-05-10 09:00:00']);

        return $ticket;
    }

    private function engineeringTicket(): Ticket
    {
        $ticket = TicketFactory::new()->withoutDetails()->forDepartment($this->engineeringDepartment())
            ->requestedAt('2026-05-11 09:00:00')->create(['ticket_number' => '05-002-2026']);

        TicketEngineeringFactory::new()->state(['ticket_id' => $ticket->id])->create([
            'nature_of_work' => NatureOfWorkFactory::new()->create(['label' => 'Carpentry'])->id,
            'scope_of_work' => 'Fix door',
            'name_of_area' => 'ICU',
        ]);
        TicketDateActivityFactory::new()->for($ticket, 'ticket')->requested()
            ->create(['requestor' => 'Maria Santos', 'created_at' => '2026-05-11 09:00:00']);

        return $ticket;
    }

    public function test_matching_it_ticket_returns_labels_and_dates(): void
    {
        $this->itTicket();

        $this->postJson(self::URL, ['ticket_number' => '05-001-2026', 'requestor' => 'Juan Dela Cruz'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('result.ticket.ticket_number', '05-001-2026')
            ->assertJsonPath('result.ticket.status', 'Open')
            ->assertJsonPath('result.ticket.department', 'IT')
            ->assertJsonPath('result.ticket.name_of_area', 'Pharmacy')
            ->assertJsonPath('result.ticket.details', 'Printer jam')
            ->assertJsonPath('result.ticket.assigned_at', null)
            ->assertJsonPath('result.ticket.completed_at', null)
            ->assertJsonStructure(['result' => ['ticket' => [
                'ticket_number', 'status', 'priority', 'department', 'name_of_area', 'details',
                'requested_at', 'assigned_at', 'completed_at',
            ]]]);
    }

    public function test_engineering_ticket_returns_the_scope_of_work(): void
    {
        $this->engineeringTicket();

        $this->postJson(self::URL, ['ticket_number' => '05-002-2026', 'requestor' => 'Maria Santos'])
            ->assertOk()
            ->assertJsonPath('result.ticket.department', 'Engineering')
            ->assertJsonPath('result.ticket.name_of_area', 'ICU')
            ->assertJsonPath('result.ticket.details', 'Fix door');
    }

    public function test_dates_follow_the_assigned_and_completed_activities(): void
    {
        $assignee = User::factory()->inDepartment($this->itDepartment())->create();
        $ticket = $this->itTicket();
        TicketDateActivityFactory::new()->for($ticket, 'ticket')->assigned($assignee)
            ->create(['created_at' => '2026-05-11 10:00:00']);
        TicketDateActivityFactory::new()->for($ticket, 'ticket')->completed($assignee)
            ->create(['created_at' => '2026-05-12 11:00:00']);

        $response = $this->postJson(self::URL, ['ticket_number' => '05-001-2026', 'requestor' => 'Juan Dela Cruz'])
            ->assertOk();

        $this->assertStringStartsWith('2026-05-10', $response->json('result.ticket.requested_at'));
        $this->assertStringStartsWith('2026-05-11', $response->json('result.ticket.assigned_at'));
        $this->assertStringStartsWith('2026-05-12', $response->json('result.ticket.completed_at'));
    }

    public function test_name_and_ticket_number_ignore_case_and_extra_spaces(): void
    {
        $this->itTicket('Juan Dela Cruz');

        foreach (['  juan   DELA cruz ', 'JUAN DELA CRUZ', "juan\tdela  cruz"] as $name) {
            $this->postJson(self::URL, ['ticket_number' => ' 05-001-2026 ', 'requestor' => $name])->assertOk();
        }
    }

    public function test_wrong_name_and_unknown_ticket_return_the_same_404(): void
    {
        $this->itTicket();

        $wrongName = $this->postJson(self::URL, ['ticket_number' => '05-001-2026', 'requestor' => 'Someone Else'])
            ->assertNotFound();
        $unknown = $this->postJson(self::URL, ['ticket_number' => '99-999-2026', 'requestor' => 'Juan Dela Cruz'])
            ->assertNotFound();

        $this->assertSame($wrongName->getContent(), $unknown->getContent());
        $this->assertSame(['success' => false, 'message' => 'Ticket not found.'], $unknown->json());
    }

    public function test_partial_ticket_numbers_do_not_match(): void
    {
        $this->itTicket();

        $this->postJson(self::URL, ['ticket_number' => '05-001', 'requestor' => 'Juan Dela Cruz'])->assertNotFound();
        $this->postJson(self::URL, ['ticket_number' => '05-001-2026%', 'requestor' => 'Juan Dela Cruz'])->assertNotFound();
    }

    public function test_response_contains_no_ids_or_people(): void
    {
        $assignee = User::factory()->inDepartment($this->itDepartment())->create();
        $ticket = TicketFactory::new()->withoutDetails()->forDepartment($this->itDepartment())->assigned($assignee)
            ->create(['ticket_number' => '05-003-2026']);
        TicketITFactory::new()->state(['ticket_id' => $ticket->id])->create();
        TicketDateActivityFactory::new()->for($ticket, 'ticket')->requested()
            ->create(['requestor' => 'Juan Dela Cruz', 'requestorId' => 77]);
        TicketDateActivityFactory::new()->for($ticket, 'ticket')->assigned($assignee)->create();

        $body = $this->postJson(self::URL, ['ticket_number' => '05-003-2026', 'requestor' => 'Juan Dela Cruz'])
            ->assertOk()->getContent();

        foreach (['"id"', '_id"', 'requestorId', 'assignee', 'comments', 'user', $assignee->first_name, $assignee->last_name] as $needle) {
            $this->assertStringNotContainsString($needle, $body);
        }
    }

    public function test_fields_are_required_and_bounded(): void
    {
        $this->postJson(self::URL, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ticket_number', 'requestor']);

        $this->postJson(self::URL, ['ticket_number' => str_repeat('1', 51), 'requestor' => str_repeat('a', 151)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ticket_number', 'requestor']);
    }

    public function test_it_is_public(): void
    {
        $this->itTicket();

        $this->postJson(self::URL, ['ticket_number' => '05-001-2026', 'requestor' => 'Juan Dela Cruz'])
            ->assertOk();
    }

    public function test_it_is_throttled_to_ten_requests_per_minute(): void
    {
        RateLimiter::clear('track');

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->postJson(self::URL, ['ticket_number' => '99-999-2026', 'requestor' => 'Nobody'])->assertNotFound();
        }

        $this->postJson(self::URL, ['ticket_number' => '99-999-2026', 'requestor' => 'Nobody'])
            ->assertStatus(429);
    }
}
