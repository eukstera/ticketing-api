<?php

namespace Tests\Feature\Tickets;

use App\Models\Ticket;
use Tests\ApiTestCase;

class TicketCreateTest extends ApiTestCase
{
    public function test_request_ticket_with_devices_is_created_and_assigned(): void
    {
        $caller = $this->actingAsUser($this->itUser());
        $assignee = $this->itUser();

        $response = $this->postJson('/api/saveRequestTicketIT', $this->requestTicketPayload($assignee));

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['result' => ['ticket' => ['id']]]);

        $ticket = Ticket::findOrFail($response->json('result.ticket.id'));
        $this->assertSame($assignee->id, (int) $ticket->assigned_user);
        $this->assertSame($this->itDepartment()->id, (int) $ticket->department);
        $this->assertMatchesRegularExpression('/^\d{2}-\d{3}-\d{4}$/', $ticket->ticket_number);
        $this->assertDatabaseHas('devices', ['ticket_id' => $ticket->id, 'device' => 'Monitor', 'qty' => 2]);
        $this->assertDatabaseHas('tickets_it', ['ticket_id' => $ticket->id, 'name_of_area' => 'Pharmacy']);
        $this->assertDatabaseHas('ticket_date_activities', [
            'ticket_id' => $ticket->id,
            'date_category_id' => 1,
            'user_id' => $caller->id,
        ]);
        $this->assertDatabaseHas('ticket_date_activities', [
            'ticket_id' => $ticket->id,
            'date_category_id' => 2,
            'user_id' => $assignee->id,
        ]);
    }

    public function test_request_ticket_without_devices_succeeds_for_non_hardware_concerns(): void
    {
        $this->actingAsUser($this->itUser());

        $payload = $this->requestTicketPayload($this->itUser(), ['type_of_concern' => 'Software']);
        unset($payload['devices']);

        $response = $this->postJson('/api/saveRequestTicketIT', $payload);

        $response->assertCreated();
        $this->assertDatabaseCount('devices', 0);
        $this->assertDatabaseCount('tickets', 1);
    }

    public function test_request_ticket_with_null_devices_succeeds_for_non_hardware_concerns(): void
    {
        $this->actingAsUser($this->itUser());

        $this->postJson('/api/saveRequestTicketIT', $this->requestTicketPayload($this->itUser(), [
            'type_of_concern' => 'Software',
            'devices' => null,
        ]))->assertCreated();

        $this->assertDatabaseCount('devices', 0);
    }

    public function test_hardware_concern_requires_devices(): void
    {
        $this->actingAsUser($this->itUser());

        $payload = $this->requestTicketPayload($this->itUser());
        unset($payload['devices']);

        $this->postJson('/api/saveRequestTicketIT', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['devices']);

        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_request_ticket_validates_references_and_quantities(): void
    {
        $this->actingAsUser($this->itUser());

        $this->postJson('/api/saveRequestTicketIT', $this->requestTicketPayload($this->itUser(), [
            'priority' => 9999,
            'service_department' => 9999,
            'assigned_user' => 9999,
            'devices' => [['type' => 'Monitor', 'quantity' => 0]],
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['priority', 'service_department', 'assigned_user', 'devices.0.quantity']);

        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_request_ticket_requires_all_core_fields(): void
    {
        $this->actingAsUser($this->itUser());

        $this->postJson('/api/saveRequestTicketIT', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'service_department', 'area_supervisor', 'type_of_concern', 'name_of_area',
                'remarks', 'assigned_user', 'priority', 'requestor',
            ]);
    }

    public function test_request_ticket_requires_authentication(): void
    {
        $this->postJson('/api/saveRequestTicketIT', $this->requestTicketPayload($this->itUser()))
            ->assertUnauthorized()
            ->assertJsonPath('success', false);
    }

    public function test_failed_creation_leaves_no_partial_rows(): void
    {
        $this->actingAsUser($this->itUser());

        $this->postJson('/api/saveRequestTicketIT', $this->requestTicketPayload($this->itUser(), [
            'devices' => [['type' => 'Monitor']],
        ]))->assertUnprocessable();

        $this->assertDatabaseCount('tickets', 0);
        $this->assertDatabaseCount('tickets_it', 0);
        $this->assertDatabaseCount('ticket_date_activities', 0);
    }

    public function test_consecutive_tickets_get_sequential_unique_numbers(): void
    {
        $this->actingAsUser($this->itUser());
        $assignee = $this->itUser();

        $first = $this->postJson('/api/saveRequestTicketIT', $this->requestTicketPayload($assignee))
            ->assertCreated()->json('result.ticket.ticket_number');
        $second = $this->postJson('/api/saveRequestTicketIT', $this->requestTicketPayload($assignee))
            ->assertCreated()->json('result.ticket.ticket_number');

        $this->assertNotSame($first, $second);
        $this->assertSame(now()->format('m').'-001-'.now()->format('Y'), $first);
        $this->assertSame(now()->format('m').'-002-'.now()->format('Y'), $second);
    }

    public function test_public_it_ticket_without_devices_is_created(): void
    {
        $response = $this->postJson('/api/saveTicketIT', [
            'service_department' => $this->itDepartment()->id,
            'area_supervisor' => 'Jane Supervisor',
            'type_of_concern' => 'Software',
            'name_of_area' => 'Pharmacy',
            'remarks' => 'Cannot log in',
            'priority' => 1,
            'requestor' => 'Juan Dela Cruz',
            'requestor_meta' => ['id' => 77],
        ]);

        $response->assertCreated()->assertJsonPath('success', true);
        $this->assertDatabaseHas('ticket_date_activities', ['requestor' => 'Juan Dela Cruz', 'requestorId' => 77]);
    }

    public function test_public_it_ticket_reports_validation_errors_as_422(): void
    {
        $this->postJson('/api/saveTicketIT', [])
            ->assertUnprocessable()
            ->assertJsonPath('success', false);
    }

    public function test_public_engineering_ticket_is_created(): void
    {
        $response = $this->postJson('/api/saveTicketEngineering', [
            'service_department' => $this->engineeringDepartment()->id,
            'nature_of_work' => 'Electrical',
            'scope_of_work' => 'Replace breaker',
            'name_of_area' => 'ICU',
            'priority' => 5,
            'requestor' => 'Juan Dela Cruz',
            'requestor_meta' => ['id' => 77],
        ]);

        $response->assertCreated()->assertJsonPath('success', true);
        $this->assertDatabaseHas('tickets_engineering', ['scope_of_work' => 'Replace breaker']);
    }
}
