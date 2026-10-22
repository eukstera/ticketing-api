<?php

namespace Tests\Feature\Auth;

use App\Models\Comment;
use Illuminate\Support\Facades\Gate;
use Tests\ApiTestCase;

class ForbiddenMessagesTest extends ApiTestCase
{
    public function test_admin_gate_denial_uses_the_friendly_message(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/admin/users')
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Only Department Heads can manage this.');
    }

    public function test_assign_denial_uses_the_friendly_message(): void
    {
        $this->actingAsUser($this->engineeringUser());
        $ticket = $this->openItTicket();

        $this->postJson("/api/tickets/{$ticket->id}/assign", ['user_id' => $this->itUser()->id])
            ->assertForbidden()
            ->assertJsonPath('message', 'You can only assign tickets in your department, to members of that department.');
    }

    public function test_complete_denial_uses_the_friendly_message(): void
    {
        $this->actingAsUser($this->engineeringUser());
        $ticket = $this->openItTicket();

        $this->postJson("/api/tickets/{$ticket->id}/complete", ['notes' => 'Replaced the faulty power supply and tested.'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Only the assignee or members of the handling department can complete this ticket.');
    }

    public function test_view_denial_uses_the_friendly_message(): void
    {
        $this->actingAsUser($this->engineeringUser());
        $ticket = $this->openItTicket();

        $this->getJson("/api/tickets/{$ticket->id}")
            ->assertForbidden()
            ->assertJsonPath('message', 'You can only view tickets from your department or tickets you requested.');
    }

    public function test_comment_policy_messages(): void
    {
        $outsider = $this->engineeringUser();
        $ticket = $this->openItTicket();
        $comment = Comment::create(['ticket_id' => $ticket->id, 'user_id' => $this->itUser()->id, 'comment' => 'Hello']);

        $viewComments = Gate::forUser($outsider)->inspect('viewComments', $ticket);
        $this->assertTrue($viewComments->denied());
        $this->assertSame('You can only view or comment on tickets from your department or tickets you requested.', $viewComments->message());

        $this->assertSame($viewComments->message(), Gate::forUser($outsider)->inspect('comment', $ticket)->message());

        $update = Gate::forUser($outsider)->inspect('update', $comment);
        $this->assertTrue($update->denied());
        $this->assertSame('You can only edit your own comments.', $update->message());
    }
}
