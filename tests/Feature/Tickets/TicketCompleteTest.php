<?php

namespace Tests\Feature\Tickets;

use App\Models\TicketDateActivity;
use Database\Factories\TicketFactory;
use Tests\ApiTestCase;

class TicketCompleteTest extends ApiTestCase
{
    public function test_assignee_can_complete_their_ticket(): void
    {
        $assignee = $this->actingAsUser($this->itUser());
        $ticket = TicketFactory::new()->assigned($assignee)->create();

        $this->postJson("/api/tickets/{$ticket->id}/complete")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('result.ticket.id', $ticket->id);

        $this->assertSame(3, (int) $ticket->fresh()->status_id);
        $this->assertDatabaseHas('ticket_date_activities', [
            'ticket_id' => $ticket->id,
            'date_category_id' => 3,
            'user_id' => $assignee->id,
        ]);
    }

    public function test_department_colleague_can_complete_and_is_recorded_as_the_assignee(): void
    {
        $assignee = $this->itUser();
        $this->actingAsUser($this->itUser());
        $ticket = TicketFactory::new()->assigned($assignee)->create();

        $this->postJson("/api/tickets/{$ticket->id}/complete")->assertOk();

        $this->assertDatabaseHas('ticket_date_activities', [
            'ticket_id' => $ticket->id,
            'date_category_id' => 3,
            'user_id' => $assignee->id,
        ]);
    }

    public function test_unassigned_ticket_cannot_be_completed(): void
    {
        $this->actingAsUser($this->itUser());
        $ticket = $this->openItTicket();

        $this->postJson("/api/tickets/{$ticket->id}/complete")
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertSame(1, (int) $ticket->fresh()->status_id);
        $this->assertSame(0, TicketDateActivity::where('date_category_id', 3)->count());
    }

    public function test_completed_ticket_cannot_be_completed_again(): void
    {
        $assignee = $this->actingAsUser($this->itUser());
        $ticket = TicketFactory::new()->assigned($assignee)->completed()->create();
        $before = TicketDateActivity::where('ticket_id', $ticket->id)->count();

        $this->postJson("/api/tickets/{$ticket->id}/complete")
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertSame($before, TicketDateActivity::where('ticket_id', $ticket->id)->count());
    }

    public function test_overdue_ticket_can_still_be_completed(): void
    {
        $assignee = $this->actingAsUser($this->itUser());
        $ticket = TicketFactory::new()->assigned($assignee)->overdue()->create();

        $this->postJson("/api/tickets/{$ticket->id}/complete")->assertOk();

        $this->assertSame(3, (int) $ticket->fresh()->status_id);
    }

    public function test_user_from_another_department_cannot_complete(): void
    {
        $assignee = $this->itUser();
        $this->actingAsUser($this->engineeringUser());
        $ticket = TicketFactory::new()->assigned($assignee)->create();

        $this->postJson("/api/tickets/{$ticket->id}/complete")
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->assertSame(2, (int) $ticket->fresh()->status_id);
    }

    public function test_unknown_ticket_returns_404(): void
    {
        $this->actingAsUser($this->itUser());

        $this->postJson('/api/tickets/9999/complete')->assertNotFound();
    }

    public function test_completing_requires_authentication(): void
    {
        $ticket = TicketFactory::new()->assigned($this->itUser())->create();

        $this->postJson("/api/tickets/{$ticket->id}/complete")->assertUnauthorized();
    }
}
