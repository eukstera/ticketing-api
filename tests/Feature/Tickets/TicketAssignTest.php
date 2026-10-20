<?php

namespace Tests\Feature\Tickets;

use App\Models\Ticket;
use App\Models\TicketDateActivity;
use App\Models\User;
use Database\Factories\TicketFactory;
use Tests\ApiTestCase;

class TicketAssignTest extends ApiTestCase
{
    public function test_department_member_can_assign_to_a_colleague(): void
    {
        $caller = $this->actingAsUser($this->itUser());
        $colleague = $this->itUser();
        $ticket = $this->openItTicket();

        $this->postJson("/api/tickets/{$ticket->id}/assign", ['user_id' => $colleague->id])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('result.ticket.id', $ticket->id);

        $ticket->refresh();
        $this->assertSame($colleague->id, (int) $ticket->assigned_user);
        $this->assertSame(2, (int) $ticket->status_id);
        $this->assertDatabaseHas('ticket_date_activities', [
            'ticket_id' => $ticket->id,
            'date_category_id' => 2,
            'user_id' => $colleague->id,
        ]);
        $this->assertNotSame($caller->id, (int) $ticket->assigned_user);
    }

    public function test_member_can_take_a_ticket_by_assigning_it_to_themselves(): void
    {
        $caller = $this->actingAsUser($this->itUser());
        $ticket = $this->openItTicket();

        $this->postJson("/api/tickets/{$ticket->id}/assign", ['user_id' => $caller->id])->assertOk();

        $this->assertSame($caller->id, (int) $ticket->fresh()->assigned_user);
        $this->assertSame(2, (int) $ticket->fresh()->status_id);
    }

    public function test_reassigning_updates_the_existing_assigned_activity(): void
    {
        $this->actingAsUser($this->itUser());
        $first = $this->itUser();
        $second = $this->itUser();
        $ticket = $this->openItTicket();

        $this->postJson("/api/tickets/{$ticket->id}/assign", ['user_id' => $first->id])->assertOk();
        $this->postJson("/api/tickets/{$ticket->id}/assign", ['user_id' => $second->id])->assertOk();

        $activities = TicketDateActivity::where('ticket_id', $ticket->id)->where('date_category_id', 2)->get();
        $this->assertCount(1, $activities);
        $this->assertSame($second->id, (int) $activities->first()->user_id);
        $this->assertSame($second->id, (int) $ticket->fresh()->assigned_user);
    }

    public function test_user_from_another_department_cannot_assign(): void
    {
        $this->actingAsUser($this->engineeringUser());
        $ticket = $this->openItTicket();

        $this->postJson("/api/tickets/{$ticket->id}/assign", ['user_id' => $this->itUser()->id])
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->assertNull($ticket->fresh()->assigned_user);
        $this->assertSame(1, (int) $ticket->fresh()->status_id);
    }

    public function test_admin_can_assign_in_any_department(): void
    {
        $this->actingAsAdmin(User::factory()->inDepartment($this->engineeringDepartment())->departmentHead()->create());
        $ticket = $this->openItTicket();

        $this->postJson("/api/tickets/{$ticket->id}/assign", ['user_id' => $this->itUser()->id])->assertOk();
    }

    public function test_assignee_must_belong_to_the_ticket_department(): void
    {
        $this->actingAsUser($this->itUser());
        $ticket = $this->openItTicket();

        $this->postJson("/api/tickets/{$ticket->id}/assign", ['user_id' => $this->engineeringUser()->id])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['user_id']);
    }

    public function test_assignee_must_exist(): void
    {
        $this->actingAsUser($this->itUser());
        $ticket = $this->openItTicket();

        $this->postJson("/api/tickets/{$ticket->id}/assign", ['user_id' => 9999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user_id']);

        $this->postJson("/api/tickets/{$ticket->id}/assign", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user_id']);
    }

    public function test_completed_ticket_cannot_be_assigned(): void
    {
        $this->actingAsUser($this->itUser());
        $ticket = TicketFactory::new()->forDepartment($this->itDepartment())->completed()->create();
        $originalAssignee = $ticket->assigned_user;

        $this->postJson("/api/tickets/{$ticket->id}/assign", ['user_id' => $this->itUser()->id])
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertSame($originalAssignee, $ticket->fresh()->assigned_user);
        $this->assertSame(3, (int) $ticket->fresh()->status_id);
    }

    public function test_unknown_ticket_returns_404(): void
    {
        $this->actingAsUser($this->itUser());

        $this->postJson('/api/tickets/9999/assign', ['user_id' => $this->itUser()->id])
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_assigning_requires_authentication(): void
    {
        $ticket = $this->openItTicket();

        $this->postJson("/api/tickets/{$ticket->id}/assign", ['user_id' => $this->itUser()->id])
            ->assertUnauthorized();

        $this->assertSame(0, Ticket::whereNotNull('assigned_user')->count());
    }
}
