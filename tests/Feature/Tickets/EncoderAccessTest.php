<?php

namespace Tests\Feature\Tickets;

use App\Models\Ticket;
use App\Models\User;
use Database\Factories\CommentFactory;
use Database\Factories\TicketFactory;
use Tests\ApiTestCase;

class EncoderAccessTest extends ApiTestCase
{
    private const MESSAGE = 'Encoders have read-only access.';

    private function encoder(): User
    {
        return $this->actingAsUser(User::factory()->inDepartment($this->itDepartment())->encoder()->create());
    }

    public function test_the_encoder_role_is_listed_and_shown_on_me(): void
    {
        $encoder = $this->encoder();

        $this->assertContains('Encoder', array_column($this->getJson('/api/roles')->assertOk()->json('result.roles'), 'name'));
        $this->getJson('/api/me')->assertOk()->assertJsonPath('result.user.role.name', 'Encoder')->assertJsonPath('result.user.id', $encoder->id);
    }

    public function test_an_encoder_can_read_tickets_and_comments_of_their_own_department(): void
    {
        $encoder = $this->encoder();
        $ticket = $this->openItTicket();
        CommentFactory::new()->state(['ticket_id' => $ticket->id, 'user_id' => $this->itUser()->id])->create();

        $this->getJson("/api/tickets/{$ticket->id}")->assertOk();
        $this->getJson("/api/tickets/{$ticket->id}/comments")->assertOk();
        $this->getJson('/api/getDepartmentTickets')->assertOk();
        $this->assertSame($this->itDepartment()->id, (int) $encoder->department_id);
    }

    public function test_an_encoder_cannot_read_another_departments_ticket(): void
    {
        $this->encoder();
        $ticket = TicketFactory::new()->forDepartment($this->engineeringDepartment())->open()->create();

        $this->getJson("/api/tickets/{$ticket->id}")->assertForbidden();
        $this->getJson("/api/tickets/{$ticket->id}/comments")->assertForbidden();
    }

    public function test_an_encoder_cannot_assign_or_take(): void
    {
        $encoder = $this->encoder();
        $ticket = $this->openItTicket();

        $this->postJson("/api/tickets/{$ticket->id}/assign", ['user_id' => $encoder->id])
            ->assertForbidden()->assertJsonPath('message', self::MESSAGE);
        $this->postJson("/api/tickets/{$ticket->id}/assign", ['user_id' => $this->itUser()->id])
            ->assertForbidden()->assertJsonPath('message', self::MESSAGE);

        $this->assertNull($ticket->fresh()->assigned_user);
    }

    public function test_an_encoder_cannot_mark_a_false_alarm_complete_or_reopen(): void
    {
        $encoder = $this->encoder();
        $ticket = $this->openItTicket();
        $own = TicketFactory::new()->forDepartment($this->itDepartment())->assigned($encoder)->create();

        $this->postJson("/api/tickets/{$ticket->id}/false-alarm", ['reason' => 'Duplicate request'])
            ->assertForbidden()->assertJsonPath('message', self::MESSAGE);
        $this->postJson("/api/tickets/{$own->id}/complete", ['notes' => 'Replaced the faulty power supply and tested.'])
            ->assertForbidden()->assertJsonPath('message', self::MESSAGE);
        $this->postJson("/api/tickets/{$ticket->id}/reopen", [])
            ->assertForbidden()->assertJsonPath('message', self::MESSAGE);

        $this->assertSame('Open', $ticket->fresh()->ticketStatus->label);
        $this->assertSame('In-progress', $own->fresh()->ticketStatus->label);
    }

    public function test_an_encoder_cannot_close_for_the_requester_or_resend_the_email(): void
    {
        $this->encoder();
        $ticket = $this->openItTicket();

        $this->postJson("/api/tickets/{$ticket->id}/confirmation/close", ['reason' => 'Requester has no email'])
            ->assertForbidden()->assertJsonPath('message', self::MESSAGE);
        $this->postJson("/api/tickets/{$ticket->id}/confirmation/resend")
            ->assertForbidden()->assertJsonPath('message', self::MESSAGE);
    }

    public function test_an_encoder_cannot_create_edit_or_delete_comments(): void
    {
        $encoder = $this->encoder();
        $ticket = $this->openItTicket();
        $comment = CommentFactory::new()->state(['ticket_id' => $ticket->id, 'user_id' => $encoder->id])->create();

        $this->postJson("/api/tickets/{$ticket->id}/comments", ['comment' => 'Hello'])
            ->assertForbidden()->assertJsonPath('message', self::MESSAGE);
        $this->patchJson("/api/tickets/comments/{$comment->id}", ['comment' => 'Edited'])
            ->assertForbidden()->assertJsonPath('message', self::MESSAGE);
        $this->deleteJson("/api/tickets/comments/{$comment->id}")
            ->assertForbidden()->assertJsonPath('message', self::MESSAGE);

        $this->assertDatabaseHas('comments', ['id' => $comment->id]);
    }

    public function test_an_encoder_can_still_create_a_ticket_as_a_requester_and_export_a_job_order(): void
    {
        $this->encoder();
        $assignee = $this->itUser();

        $created = $this->postJson('/api/saveRequestTicketIT', $this->requestTicketPayload($assignee))->assertCreated();
        $this->assertSame(1, Ticket::query()->count());

        $this->get('/api/tickets/'.$created->json('result.ticket.id').'/job-order')->assertOk();
    }

    public function test_a_normal_user_keeps_their_abilities(): void
    {
        $user = $this->actingAsUser($this->itUser());
        $ticket = $this->openItTicket();

        $this->postJson("/api/tickets/{$ticket->id}/assign", ['user_id' => $user->id])->assertOk();
        $this->postJson("/api/tickets/{$ticket->id}/comments", ['comment' => 'On it'])->assertSuccessful();
    }
}
