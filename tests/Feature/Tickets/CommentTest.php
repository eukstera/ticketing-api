<?php

namespace Tests\Feature\Tickets;

use App\Models\Comment;
use App\Models\TicketDateActivity;
use Database\Factories\CommentFactory;
use Database\Factories\TicketFactory;
use PHPUnit\Framework\Attributes\Group;
use Tests\ApiTestCase;

class CommentTest extends ApiTestCase
{
    public function test_department_member_can_list_comments_oldest_first(): void
    {
        $user = $this->actingAsUser($this->itUser());
        $ticket = $this->openItTicket();
        CommentFactory::new()->state(['ticket_id' => $ticket->id, 'user_id' => $user->id, 'comment' => 'second'])
            ->create(['created_at' => now()]);
        CommentFactory::new()->state(['ticket_id' => $ticket->id, 'user_id' => $user->id, 'comment' => 'first'])
            ->create(['created_at' => now()->subHour()]);

        $response = $this->getJson("/api/tickets/{$ticket->id}/comments");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('result.comments.0.comment', 'first')
            ->assertJsonPath('result.comments.1.comment', 'second')
            ->assertJsonPath('result.comments.0.user.id', $user->id);
        $this->assertSame(
            ['id', 'first_name', 'last_name'],
            array_keys($response->json('result.comments.0.user'))
        );
    }

    public function test_requester_can_read_and_write_comments_on_their_ticket(): void
    {
        $requester = $this->actingAsUser($this->engineeringUser());
        $ticket = $this->openItTicket();
        TicketDateActivity::where('ticket_id', $ticket->id)->where('date_category_id', 1)
            ->update(['user_id' => $requester->id]);

        $this->getJson("/api/tickets/{$ticket->id}/comments")->assertOk();
        $this->postJson("/api/tickets/{$ticket->id}/comments", ['comment' => 'Any update?'])->assertCreated();
    }

    public function test_user_without_access_cannot_read_or_write_comments(): void
    {
        $this->actingAsUser($this->engineeringUser());
        $ticket = $this->openItTicket();

        $this->getJson("/api/tickets/{$ticket->id}/comments")
            ->assertForbidden()
            ->assertJsonPath('success', false);
        $this->postJson("/api/tickets/{$ticket->id}/comments", ['comment' => 'Hello'])->assertForbidden();

        $this->assertDatabaseCount('comments', 0);
    }

    public function test_store_creates_a_comment_with_a_minimal_author_shape(): void
    {
        $user = $this->actingAsUser($this->itUser());
        $ticket = $this->openItTicket();

        $response = $this->postJson("/api/tickets/{$ticket->id}/comments", ['comment' => 'Checked the printer']);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('result.comment.comment', 'Checked the printer')
            ->assertJsonPath('result.comment.user.id', $user->id);
        $this->assertSame(
            ['id', 'first_name', 'last_name'],
            array_keys($response->json('result.comment.user'))
        );
        $this->assertDatabaseHas('comments', ['ticket_id' => $ticket->id, 'user_id' => $user->id]);
    }

    public function test_store_validates_the_comment(): void
    {
        $this->actingAsUser($this->itUser());
        $ticket = $this->openItTicket();

        $this->postJson("/api/tickets/{$ticket->id}/comments", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['comment']);
        $this->postJson("/api/tickets/{$ticket->id}/comments", ['comment' => str_repeat('a', 2001)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['comment']);
        $this->postJson("/api/tickets/{$ticket->id}/comments", ['comment' => str_repeat('a', 2000)])
            ->assertCreated();
    }

    public function test_comments_require_authentication(): void
    {
        $ticket = $this->openItTicket();

        $this->getJson("/api/tickets/{$ticket->id}/comments")->assertUnauthorized();
        $this->postJson("/api/tickets/{$ticket->id}/comments", ['comment' => 'x'])->assertUnauthorized();
    }

    public function test_author_can_edit_their_comment(): void
    {
        $author = $this->actingAsUser($this->itUser());
        $comment = CommentFactory::new()->state([
            'ticket_id' => TicketFactory::new()->forDepartment($this->itDepartment()),
            'user_id' => $author->id,
        ])->create();

        $response = $this->patchJson("/api/tickets/comments/{$comment->id}", ['comment' => 'Edited']);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('result.comment.comment', 'Edited')
            ->assertJsonPath('result.comment.user.id', $author->id);
        $this->assertSame(
            ['id', 'first_name', 'last_name'],
            array_keys($response->json('result.comment.user'))
        );
        $this->assertSame('Edited', $comment->fresh()->comment);
    }

    public function test_non_author_cannot_edit_a_comment(): void
    {
        $this->actingAsUser($this->itUser());
        $comment = CommentFactory::new()->create(['comment' => 'Original']);

        $this->patchJson("/api/tickets/comments/{$comment->id}", ['comment' => 'Hijacked'])
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->assertSame('Original', $comment->fresh()->comment);
    }

    public function test_admin_cannot_edit_someone_elses_comment(): void
    {
        $this->actingAsAdmin();
        $comment = CommentFactory::new()->create(['comment' => 'Original']);

        $this->patchJson("/api/tickets/comments/{$comment->id}", ['comment' => 'Hijacked'])->assertForbidden();
    }

    public function test_edit_validates_length(): void
    {
        $author = $this->actingAsUser($this->itUser());
        $comment = CommentFactory::new()->create(['user_id' => $author->id]);

        $this->patchJson("/api/tickets/comments/{$comment->id}", ['comment' => str_repeat('a', 2001)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['comment']);
    }

    #[Group('phase2')]
    public function test_author_can_delete_their_comment(): void
    {
        $author = $this->actingAsUser($this->itUser());
        $comment = CommentFactory::new()->create(['user_id' => $author->id]);

        $this->deleteJson("/api/tickets/comments/{$comment->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertNull(Comment::find($comment->id));
    }

    #[Group('phase2')]
    public function test_admin_can_delete_any_comment(): void
    {
        $this->actingAsAdmin();
        $comment = CommentFactory::new()->create();

        $this->deleteJson("/api/tickets/comments/{$comment->id}")->assertOk();

        $this->assertNull(Comment::find($comment->id));
    }

    #[Group('phase2')]
    public function test_other_users_cannot_delete_a_comment(): void
    {
        $this->actingAsUser($this->itUser());
        $comment = CommentFactory::new()->create();

        $this->deleteJson("/api/tickets/comments/{$comment->id}")
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->assertNotNull(Comment::find($comment->id));
    }
}
