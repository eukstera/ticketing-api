<?php

namespace Tests\Feature\Tickets;

use App\Models\TicketDateActivity;
use Tests\ApiTestCase;

class AnonymousSubmissionTest extends ApiTestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function itPayload(array $overrides = []): array
    {
        return array_merge([
            'service_department' => $this->itDepartment()->id,
            'area_supervisor' => 'Jane Supervisor',
            'type_of_concern' => 'Software',
            'name_of_area' => 'Pharmacy',
            'remarks' => 'Cannot log in',
            'priority' => 1,
            'requestor' => 'Juan Dela Cruz',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function engineeringPayload(array $overrides = []): array
    {
        return array_merge([
            'service_department' => $this->engineeringDepartment()->id,
            'nature_of_work' => 'Electrical',
            'scope_of_work' => 'Replace breaker',
            'name_of_area' => 'ICU',
            'priority' => 5,
            'requestor' => 'Maria Santos',
        ], $overrides);
    }

    public function test_it_ticket_can_be_submitted_without_requestor_meta(): void
    {
        $response = $this->postJson('/api/saveTicketIT', $this->itPayload())->assertCreated();

        $this->assertNull(TicketDateActivity::where('ticket_id', $response->json('result.ticket.id'))->value('requestorId'));
        $this->assertDatabaseHas('ticket_date_activities', ['requestor' => 'Juan Dela Cruz', 'requestorId' => null]);
    }

    public function test_engineering_ticket_can_be_submitted_without_requestor_meta(): void
    {
        $response = $this->postJson('/api/saveTicketEngineering', $this->engineeringPayload())->assertCreated();

        $this->assertNull(TicketDateActivity::where('ticket_id', $response->json('result.ticket.id'))->value('requestorId'));
    }

    public function test_requestor_meta_id_is_still_stored_when_sent(): void
    {
        $this->postJson('/api/saveTicketIT', $this->itPayload(['requestor_meta' => ['id' => 77]]))->assertCreated();
        $this->postJson('/api/saveTicketEngineering', $this->engineeringPayload(['requestor_meta' => ['id' => 78]]))->assertCreated();

        $this->assertDatabaseHas('ticket_date_activities', ['requestor' => 'Juan Dela Cruz', 'requestorId' => 77]);
        $this->assertDatabaseHas('ticket_date_activities', ['requestor' => 'Maria Santos', 'requestorId' => 78]);
    }

    public function test_requestor_is_still_required(): void
    {
        $this->postJson('/api/saveTicketIT', $this->itPayload(['requestor' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['requestor']);
        $this->postJson('/api/saveTicketEngineering', $this->engineeringPayload(['requestor' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['requestor']);
    }

    public function test_track_finds_an_anonymous_ticket_by_number_and_name(): void
    {
        $number = $this->postJson('/api/saveTicketIT', $this->itPayload())->assertCreated()->json('result.ticket.ticket_number');

        $this->postJson('/api/track', ['ticket_number' => $number, 'requestor' => ' juan  DELA cruz '])
            ->assertOk()
            ->assertJsonPath('result.ticket.ticket_number', $number);
    }

    public function test_get_ticket_requests_requires_a_requestor_id(): void
    {
        $this->postJson('/api/saveTicketIT', $this->itPayload())->assertCreated();

        $this->getJson('/api/getTicketRequests')
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['requestorId']);
    }

    public function test_get_ticket_requests_returns_only_the_requestors_own_tickets(): void
    {
        $this->postJson('/api/saveTicketIT', $this->itPayload(['requestor_meta' => ['id' => 77]]))->assertCreated();
        $this->postJson('/api/saveTicketIT', $this->itPayload(['requestor' => 'Anonymous Person']))->assertCreated();

        $response = $this->getJson('/api/getTicketRequests?requestorId=77')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertCount(1, $response->json('result.ticketsIT'));
    }
}
