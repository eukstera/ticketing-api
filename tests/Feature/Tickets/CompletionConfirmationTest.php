<?php

namespace Tests\Feature\Tickets;

use App\Mail\TicketCompletionMail;
use App\Mail\TicketDisputedMail;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\TicketConfirmation;
use App\Models\TicketDateActivity;
use App\Models\TicketMailLog;
use App\Models\TicketSurvey;
use App\Models\User;
use Database\Factories\TicketFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\ApiTestCase;

class CompletionConfirmationTest extends ApiTestCase
{
    private const NOTES = 'Replaced the faulty power supply and tested.';

    private const RATINGS = [
        'service' => 5, 'assurance' => 4, 'reliability' => 5, 'communication' => 3,
        'access_facilities' => 4, 'integrity' => 5, 'outcome' => 5,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config(['tickets.requestor_portal_url' => 'http://portal.test']);
    }

    private function takenTicket(?string $email = 'juan@hospital.test', ?User $assignee = null): Ticket
    {
        $ticket = TicketFactory::new()->forDepartment($this->itDepartment())
            ->assigned($assignee ?? $this->itUser())->create(['requester_email' => $email]);
        TicketDateActivity::query()->where('ticket_id', $ticket->id)->where('date_category_id', 1)
            ->update(['requestor' => 'Juan Dela Cruz', 'user_id' => null]);

        return $ticket;
    }

    /**
     * Completes the ticket as its assignee and returns the emailed link token, if any.
     *
     * @return array{0: Ticket, 1: ?string}
     */
    private function completed(?string $email = 'juan@hospital.test'): array
    {
        $assignee = $this->itUser();
        $ticket = $this->takenTicket($email, $assignee);

        $this->actingAsUser($assignee);
        $this->postJson("/api/tickets/{$ticket->id}/complete", ['notes' => self::NOTES])->assertOk();

        return [$ticket->fresh(), $this->emailedToken()];
    }

    private function emailedToken(): ?string
    {
        $mail = Mail::sent(TicketCompletionMail::class)->last();

        return $mail === null ? null : substr($mail->url, strrpos($mail->url, '/') + 1);
    }

    private function statusLabel(Ticket $ticket): string
    {
        return $ticket->fresh()->ticketStatus->label;
    }

    private function expire(Ticket $ticket): void
    {
        TicketConfirmation::query()->where('ticket_id', $ticket->id)->update(['expires_at' => now()->subMinute()]);
    }

    // ---------------------------------------------------------------- staff: completing

    public function test_completing_requires_notes(): void
    {
        $assignee = $this->actingAsUser($this->itUser());
        $ticket = $this->takenTicket(assignee: $assignee);

        foreach ([[], ['notes' => ''], ['notes' => 'too short'], ['notes' => str_repeat('a', 2001)], ['notes' => ['x']]] as $body) {
            $this->postJson("/api/tickets/{$ticket->id}/complete", $body)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['notes']);
        }

        $this->assertSame('In-progress', $this->statusLabel($ticket));
    }

    public function test_other_departments_are_forbidden_before_validation(): void
    {
        $ticket = $this->takenTicket();
        $this->actingAsUser($this->engineeringUser());

        $this->postJson("/api/tickets/{$ticket->id}/complete", [])->assertForbidden();
    }

    public function test_completing_moves_the_ticket_to_pending_confirmation_with_an_audit_record(): void
    {
        $assignee = $this->itUser();
        $ticket = $this->takenTicket(assignee: $assignee);
        $this->actingAsUser($assignee);

        $response = $this->postJson("/api/tickets/{$ticket->id}/complete", ['notes' => '  '.self::NOTES.'  '])
            ->assertOk()
            ->assertJsonPath('message', "Ticket completed and sent for the requester's confirmation")
            ->assertJsonPath('result.confirmation.state', 'pending')
            ->assertJsonPath('result.confirmation.requester_email', 'juan@hospital.test')
            ->assertJsonPath('result.confirmation.mail.status', 'pending');

        $this->assertSame('Pending Confirmation', $this->statusLabel($ticket));
        $this->assertSame(6, (int) $response->json('result.ticket.status_id'));
        $this->assertDatabaseHas('ticket_date_activities', ['ticket_id' => $ticket->id, 'date_category_id' => 3, 'user_id' => $assignee->id, 'remarks' => self::NOTES]);

        $confirmation = TicketConfirmation::query()->where('ticket_id', $ticket->id)->firstOrFail();
        $this->assertSame(self::NOTES, $confirmation->completion_notes);
        $this->assertSame($assignee->id, $confirmation->completed_by);
        $this->assertSame(64, strlen($confirmation->token_hash));
        $this->assertSame('pending', $confirmation->state);
        $this->assertEqualsWithDelta(30 * 86400, $confirmation->completed_at->diffInSeconds($confirmation->expires_at, true), 5);
    }

    public function test_the_link_email_goes_out_after_the_response_and_the_token_is_stored_only_as_a_hash(): void
    {
        [$ticket, $token] = $this->completed();

        $this->assertNotNull($token);
        $this->assertSame(48, strlen($token));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{48}$/', $token);
        Mail::assertSent(TicketCompletionMail::class, fn ($mail) => $mail->hasTo('juan@hospital.test') && $mail->url === 'http://portal.test/confirm/'.$token);

        $confirmation = $ticket->confirmations()->firstOrFail();
        $this->assertSame(hash('sha256', $token), $confirmation->token_hash);
        $this->assertNotSame($token, $confirmation->token_hash);
        $this->assertDatabaseMissing('ticket_confirmations', ['token_hash' => $token]);

        $log = TicketMailLog::query()->firstOrFail();
        $this->assertSame('sent', $log->status);
        $this->assertSame(1, $log->attempts);
        $this->assertNotNull($log->sent_at);
    }

    public function test_a_ticket_without_an_email_still_waits_for_confirmation(): void
    {
        [$ticket, $token] = $this->completed(null);

        $this->assertNull($token);
        Mail::assertNothingSent();
        $this->assertSame('Pending Confirmation', $this->statusLabel($ticket));
        $this->assertDatabaseCount('ticket_mail_log', 0);

        $this->getJson("/api/tickets/{$ticket->id}")
            ->assertOk()
            ->assertJsonPath('result.ticket.requester_email', null)
            ->assertJsonPath('result.ticket.confirmation.mail.status', 'none');
    }

    public function test_a_mail_failure_never_blocks_completion_and_is_logged_without_secrets(): void
    {
        config(['mail.mailers.smtp.password' => 'super-secret-pw']);
        Mail::shouldReceive('to')->andThrow(new RuntimeException('Authentication failed for super-secret-pw on smtp.example'));
        $assignee = $this->itUser();
        $ticket = $this->takenTicket(assignee: $assignee);
        $this->actingAsUser($assignee);

        $this->postJson("/api/tickets/{$ticket->id}/complete", ['notes' => self::NOTES])->assertOk();

        $this->assertSame('Pending Confirmation', $this->statusLabel($ticket));
        $log = TicketMailLog::query()->firstOrFail();
        $this->assertSame('failed', $log->status);
        $this->assertSame(1, $log->attempts);
        $this->assertNotNull($log->next_attempt_at);
        $this->assertStringContainsString('Authentication failed', $log->last_error);
        $this->assertStringNotContainsString('super-secret-pw', $log->last_error);
    }

    public function test_the_scheduler_retries_failed_mail_with_a_fresh_link(): void
    {
        [$ticket] = $this->completed();
        $confirmation = $ticket->confirmations()->firstOrFail();
        $oldHash = $confirmation->token_hash;
        TicketMailLog::query()->update(['status' => 'failed', 'attempts' => 1, 'sent_at' => null, 'next_attempt_at' => now()->subMinute()]);
        Mail::fake();

        $this->artisan('tickets:retry-mail')->assertSuccessful();

        $token = $this->emailedToken();
        $this->assertNotNull($token);
        $log = TicketMailLog::query()->firstOrFail();
        $this->assertSame('sent', $log->status);
        $this->assertSame(2, $log->attempts);
        $this->assertNotSame($oldHash, $confirmation->fresh()->token_hash);
        $this->getJson("/api/confirm/{$token}")->assertOk()->assertJsonPath('result.confirmation.state', 'pending');
    }

    public function test_retries_stop_after_the_maximum_attempts_and_skip_answered_tickets(): void
    {
        [$ticket] = $this->completed();
        Mail::fake();
        TicketMailLog::query()->update(['status' => 'failed', 'attempts' => 5, 'sent_at' => null, 'next_attempt_at' => now()->subMinute()]);

        $this->artisan('tickets:retry-mail')->assertSuccessful();
        $this->assertSame('gave_up', TicketMailLog::query()->firstOrFail()->status);
        Mail::assertNothingSent();

        TicketMailLog::query()->update(['status' => 'failed', 'attempts' => 1, 'next_attempt_at' => now()->subMinute()]);
        $ticket->confirmations()->update(['state' => 'confirmed']);

        $this->artisan('tickets:retry-mail')->assertSuccessful();
        $log = TicketMailLog::query()->firstOrFail();
        $this->assertSame('gave_up', $log->status);
        $this->assertSame('No longer needed.', $log->last_error);
        Mail::assertNothingSent();
    }

    public function test_the_retry_command_leaves_mail_that_is_not_due_yet(): void
    {
        $this->completed();
        TicketMailLog::query()->update(['status' => 'failed', 'attempts' => 1, 'next_attempt_at' => now()->addMinutes(30)]);
        Mail::fake();

        $this->artisan('tickets:retry-mail')->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertSame(1, TicketMailLog::query()->firstOrFail()->attempts);
    }

    public function test_a_completed_ticket_waiting_for_confirmation_cannot_be_completed_again(): void
    {
        [$ticket] = $this->completed();

        $this->postJson("/api/tickets/{$ticket->id}/complete", ['notes' => self::NOTES])
            ->assertStatus(409)
            ->assertJsonPath('message', "This ticket is already waiting for the requester's confirmation.");
    }

    public function test_a_ticket_waiting_for_confirmation_cannot_be_reassigned(): void
    {
        [$ticket] = $this->completed();
        $this->actingAsAdmin();

        $this->postJson("/api/tickets/{$ticket->id}/assign", ['user_id' => $this->itUser()->id])
            ->assertStatus(409)
            ->assertJsonPath('message', "A ticket waiting for the requester's confirmation cannot be reassigned.");
        $this->assertSame('Pending Confirmation', $this->statusLabel($ticket));
    }

    // ---------------------------------------------------------------- staff: visibility

    public function test_lists_show_the_status_count_and_the_requester_email(): void
    {
        [$ticket] = $this->completed();

        $list = $this->getJson('/api/getDepartmentTickets')->assertOk();
        $row = collect($list->json('result.ticket.data'))->firstWhere('id', $ticket->id);
        $counts = collect($list->json('result.status_counts'));

        $this->assertSame('Pending Confirmation', $row['status']);
        $this->assertSame('juan@hospital.test', $row['requester_email']);
        $this->assertSame(1, $counts->firstWhere('label', 'Pending Confirmation')['count']);
        $this->assertSame(1, $counts->firstWhere('label', 'All')['count']);

        $filtered = $this->getJson('/api/getDepartmentTickets?status_id='.Status::where('label', 'Pending Confirmation')->value('id'))->assertOk();
        $this->assertSame([$ticket->id], array_column($filtered->json('result.ticket.data'), 'id'));

        $this->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('result.overview.pending_confirmation', 1)
            ->assertJsonPath('result.overview.completed', 0);
    }

    public function test_detail_shows_the_confirmation_record(): void
    {
        [$ticket, $token] = $this->completed();
        $this->postJson("/api/confirm/{$token}/confirm")->assertOk();

        $this->getJson("/api/tickets/{$ticket->id}")
            ->assertOk()
            ->assertJsonPath('result.ticket.requester_email', 'juan@hospital.test')
            ->assertJsonPath('result.ticket.confirmation.state', 'confirmed')
            ->assertJsonPath('result.ticket.confirmation.channel', 'email')
            ->assertJsonPath('result.ticket.confirmation.completion_notes', self::NOTES)
            ->assertJsonPath('result.ticket.confirmation.mail.status', 'sent')
            ->assertJsonPath('result.ticket.confirmation.survey.submitted', false)
            ->assertJsonStructure(['result' => ['ticket' => ['confirmation' => ['completed_by' => ['id', 'first_name', 'last_name'], 'completed_at', 'auto_close_at', 'responded_at', 'dispute_reason']]]]);
    }

    public function test_staff_see_the_submitted_survey_answers_but_requesters_and_tracking_do_not(): void
    {
        [$ticket, $token] = $this->completed();
        $this->postJson("/api/confirm/{$token}/confirm")->assertOk();
        $survey = $this->postJson("/api/confirm/{$token}/survey", ['ratings' => self::RATINGS, 'comments' => 'Quick and polite.']);
        $survey->assertCreated();
        $this->assertStringNotContainsString('Quick and polite.', $survey->getContent());

        $response = $this->getJson("/api/tickets/{$ticket->id}")->assertOk()
            ->assertJsonPath('result.ticket.confirmation.survey.submitted', true)
            ->assertJsonPath('result.ticket.confirmation.survey.comments', 'Quick and polite.');
        $this->assertSame(self::RATINGS, $response->json('result.ticket.confirmation.survey.ratings'));
        $this->assertNotNull($response->json('result.ticket.confirmation.survey.submitted_at'));

        $this->actingAsAdmin(User::factory()->inDepartment($this->engineeringDepartment())->departmentHead()->create());
        $this->getJson("/api/tickets/{$ticket->id}")->assertOk()
            ->assertJsonPath('result.ticket.confirmation.survey.ratings.service', 5);

        foreach ([$this->getJson("/api/confirm/{$token}"), $this->postJson('/api/track', ['ticket_number' => $ticket->ticket_number, 'requestor' => 'Juan Dela Cruz'])] as $public) {
            $this->assertStringNotContainsString('Quick and polite.', $public->getContent());
            $this->assertStringNotContainsString('"ratings"', $public->getContent());
        }
    }

    public function test_the_survey_object_has_no_answers_before_it_is_submitted(): void
    {
        [$ticket] = $this->completed();

        $this->getJson("/api/tickets/{$ticket->id}")->assertOk()
            ->assertJsonPath('result.ticket.confirmation.survey.submitted', false)
            ->assertJsonPath('result.ticket.confirmation.survey.ratings', null)
            ->assertJsonPath('result.ticket.confirmation.survey.comments', null);
    }

    public function test_pending_tickets_are_never_promoted_to_overdue(): void
    {
        [$ticket] = $this->completed();
        TicketDateActivity::query()->where('ticket_id', $ticket->id)->where('date_category_id', 1)->update(['created_at' => now()->subDays(60)]);

        $this->artisan('tickets:promote-overdue')->assertSuccessful();

        $this->assertSame('Pending Confirmation', $this->statusLabel($ticket));
    }

    // ---------------------------------------------------------------- public: link

    public function test_the_link_shows_a_safe_summary(): void
    {
        [$ticket, $token] = $this->completed();

        $response = $this->getJson("/api/confirm/{$token}")
            ->assertOk()
            ->assertJsonPath('result.confirmation.state', 'pending')
            ->assertJsonPath('result.confirmation.can_confirm', true)
            ->assertJsonPath('result.confirmation.can_dispute', true)
            ->assertJsonPath('result.confirmation.survey.required', false)
            ->assertJsonPath('result.confirmation.survey.submitted', false)
            ->assertJsonPath('result.confirmation.ticket.ticket_number', $ticket->ticket_number)
            ->assertJsonPath('result.confirmation.completion.notes', self::NOTES)
            ->assertJsonStructure(['result' => ['confirmation' => ['auto_close_at', 'ticket' => ['department', 'concern', 'name_of_area', 'requested_at'], 'completion' => ['completed_by', 'completed_at']]]]);

        $this->assertStringNotContainsString('juan@hospital.test', $response->getContent());
        $this->assertArrayNotHasKey('id', $response->json('result.confirmation'));
        $this->assertArrayNotHasKey('id', $response->json('result.confirmation.ticket'));
    }

    public function test_unknown_malformed_and_expired_links_are_rejected(): void
    {
        [$ticket, $token] = $this->completed();

        foreach ([str_repeat('a', 48), 'short', str_repeat('!', 48), $token.'x'] as $bad) {
            $this->getJson("/api/confirm/{$bad}")->assertNotFound()->assertJsonPath('message', 'This link is not valid.');
        }

        $this->expire($ticket);
        $this->getJson("/api/confirm/{$token}")->assertStatus(410)->assertJsonPath('message', 'This confirmation link has expired.');
        $this->postJson("/api/confirm/{$token}/confirm")->assertStatus(410);
        $this->postJson("/api/confirm/{$token}/dispute", ['reason' => 'It still does not work'])->assertStatus(410);
        $this->assertSame('Pending Confirmation', $this->statusLabel($ticket));
    }

    public function test_confirming_closes_the_ticket_and_records_who_and_how(): void
    {
        [$ticket, $token] = $this->completed();

        $this->withHeaders(['User-Agent' => 'IntranetBrowser/1.0'])
            ->postJson("/api/confirm/{$token}/confirm")
            ->assertOk()
            ->assertJsonPath('result.confirmation.state', 'confirmed')
            ->assertJsonPath('result.confirmation.can_confirm', false)
            ->assertJsonPath('result.confirmation.survey.required', true);

        $this->assertSame('Completed', $this->statusLabel($ticket));
        $this->assertDatabaseHas('ticket_date_activities', ['ticket_id' => $ticket->id, 'date_category_id' => 6]);

        $confirmation = $ticket->confirmations()->firstOrFail();
        $this->assertSame('confirmed', $confirmation->state);
        $this->assertSame('email', $confirmation->channel);
        $this->assertNotNull($confirmation->responded_at);
        $this->assertSame('127.0.0.1', $confirmation->responder_ip);
        $this->assertSame('IntranetBrowser/1.0', $confirmation->responder_user_agent);
    }

    public function test_a_second_answer_is_a_conflict(): void
    {
        [$ticket, $token] = $this->completed();
        $this->postJson("/api/confirm/{$token}/confirm")->assertOk();

        $this->postJson("/api/confirm/{$token}/confirm")->assertStatus(409)->assertJsonPath('message', 'This ticket has already been confirmed.');
        $this->postJson("/api/confirm/{$token}/dispute", ['reason' => 'It still does not work'])->assertStatus(409);

        $this->assertSame(1, TicketDateActivity::query()->where('ticket_id', $ticket->id)->where('date_category_id', 6)->count());
    }

    public function test_disputing_sends_the_ticket_back_and_notifies_staff(): void
    {
        $assignee = $this->itUser();
        $head = User::factory()->inDepartment($this->itDepartment())->departmentHead()->create();
        $otherDepartmentHead = User::factory()->inDepartment($this->engineeringDepartment())->departmentHead()->create();
        $ticket = $this->takenTicket(assignee: $assignee);
        $this->actingAsUser($assignee);
        $this->postJson("/api/tickets/{$ticket->id}/complete", ['notes' => self::NOTES])->assertOk();
        $token = $this->emailedToken();

        $this->postJson("/api/confirm/{$token}/dispute", ['reason' => '  The printer still jams every time  '])
            ->assertOk()
            ->assertJsonPath('result.confirmation.state', 'disputed');

        $ticket = $ticket->fresh();
        $this->assertSame('In-progress', $ticket->ticketStatus->label);
        $this->assertSame($assignee->id, $ticket->assigned_user);
        $this->assertDatabaseHas('ticket_date_activities', ['ticket_id' => $ticket->id, 'date_category_id' => 7, 'remarks' => 'The printer still jams every time']);
        $this->assertSame('The printer still jams every time', $ticket->confirmations()->firstOrFail()->dispute_reason);

        Mail::assertSent(TicketDisputedMail::class, 2);
        Mail::assertSent(TicketDisputedMail::class, fn ($mail) => $mail->hasTo($assignee->email));
        Mail::assertSent(TicketDisputedMail::class, fn ($mail) => $mail->hasTo($head->email));
        Mail::assertNotSent(TicketDisputedMail::class, fn ($mail) => $mail->hasTo($otherDepartmentHead->email));
    }

    public function test_a_dispute_needs_a_real_reason(): void
    {
        [, $token] = $this->completed();

        foreach ([[], ['reason' => 'no'], ['reason' => str_repeat('a', 501)]] as $body) {
            $this->postJson("/api/confirm/{$token}/dispute", $body)->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        }
    }

    public function test_after_a_dispute_a_new_completion_gets_a_new_link(): void
    {
        [$ticket, $oldToken] = $this->completed();
        $this->postJson("/api/confirm/{$oldToken}/dispute", ['reason' => 'Still broken after the visit'])->assertOk();

        $this->actingAsUser(User::query()->findOrFail($ticket->fresh()->assigned_user));
        $this->postJson("/api/tickets/{$ticket->id}/complete", ['notes' => 'Replaced the cable as well.'])->assertOk();
        $newToken = $this->emailedToken();

        $this->assertNotSame($oldToken, $newToken);
        $this->getJson("/api/confirm/{$oldToken}")->assertOk()->assertJsonPath('result.confirmation.state', 'disputed')->assertJsonPath('result.confirmation.can_confirm', false);
        $this->postJson("/api/confirm/{$newToken}/confirm")->assertOk();
        $this->assertSame('Completed', $this->statusLabel($ticket));
        $this->assertSame(2, $ticket->confirmations()->count());
    }

    // ---------------------------------------------------------------- survey

    public function test_the_survey_is_saved_once_after_confirming(): void
    {
        [$ticket, $token] = $this->completed();

        $this->postJson("/api/confirm/{$token}/survey", ['ratings' => self::RATINGS])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Please confirm the service before answering the survey.');

        $this->postJson("/api/confirm/{$token}/confirm")->assertOk();

        $this->withHeaders(['User-Agent' => 'IntranetBrowser/1.0'])
            ->postJson("/api/confirm/{$token}/survey", ['ratings' => self::RATINGS, 'comments' => '  Quick and polite.  '])
            ->assertCreated()
            ->assertJsonPath('message', 'Thank you for your feedback');

        $survey = TicketSurvey::query()->firstOrFail();
        $this->assertSame($ticket->id, $survey->ticket_id);
        foreach (self::RATINGS as $block => $rating) {
            $this->assertSame($rating, $survey->{$block}, $block);
        }
        $this->assertSame('Quick and polite.', $survey->comments);
        $this->assertSame('127.0.0.1', $survey->submitter_ip);

        $this->postJson("/api/confirm/{$token}/survey", ['ratings' => self::RATINGS])
            ->assertStatus(409)
            ->assertJsonPath('message', 'The survey was already submitted.');
        $this->assertSame(1, TicketSurvey::query()->count());

        $this->getJson("/api/confirm/{$token}")
            ->assertJsonPath('result.confirmation.survey.submitted', true)
            ->assertJsonPath('result.confirmation.survey.required', false);
    }

    public function test_survey_ratings_are_validated(): void
    {
        [, $token] = $this->completed();
        $this->postJson("/api/confirm/{$token}/confirm")->assertOk();

        $this->postJson("/api/confirm/{$token}/survey", [])->assertUnprocessable()->assertJsonValidationErrors(['ratings']);
        $this->postJson("/api/confirm/{$token}/survey", ['ratings' => array_diff_key(self::RATINGS, ['outcome' => 1])])
            ->assertUnprocessable()->assertJsonValidationErrors(['ratings.outcome']);

        foreach ([0, 6, 'high', 2.5] as $bad) {
            $this->postJson("/api/confirm/{$token}/survey", ['ratings' => ['service' => $bad] + self::RATINGS])
                ->assertUnprocessable()->assertJsonValidationErrors(['ratings.service']);
        }

        $this->postJson("/api/confirm/{$token}/survey", ['ratings' => self::RATINGS, 'comments' => str_repeat('a', 1001)])
            ->assertUnprocessable()->assertJsonValidationErrors(['comments']);
        $this->assertSame(0, TicketSurvey::query()->count());
    }

    public function test_a_survey_cannot_follow_a_dispute_or_a_head_close(): void
    {
        [, $token] = $this->completed();
        $this->postJson("/api/confirm/{$token}/dispute", ['reason' => 'Still broken after the visit'])->assertOk();

        $this->postJson("/api/confirm/{$token}/survey", ['ratings' => self::RATINGS])->assertStatus(409);
    }

    // ---------------------------------------------------------------- public: throttle

    public function test_the_public_confirmation_routes_are_throttled(): void
    {
        $last = null;
        for ($i = 0; $i < 21; $i++) {
            $last = $this->getJson('/api/confirm/'.str_repeat('a', 48));
        }

        $last->assertStatus(429);
    }

    // ---------------------------------------------------------------- Track Ticket

    public function test_track_shows_the_confirmation_only_when_the_name_matches(): void
    {
        [$ticket] = $this->completed();

        $named = $this->postJson('/api/track', ['ticket_number' => $ticket->ticket_number, 'requestor' => 'JUAN  dela cruz'])
            ->assertOk()
            ->assertJsonPath('result.ticket.status', 'Pending Confirmation')
            ->assertJsonPath('result.ticket.confirmation.state', 'pending')
            ->assertJsonPath('result.ticket.confirmation.can_confirm', true)
            ->assertJsonPath('result.ticket.confirmation.completion_notes', self::NOTES);
        $this->assertStringNotContainsString('juan@hospital.test', $named->getContent());

        $numberOnly = $this->postJson('/api/track', ['ticket_number' => $ticket->ticket_number])
            ->assertOk()
            ->assertJsonPath('result.ticket.status', 'Pending Confirmation')
            ->assertJsonMissingPath('result.ticket.confirmation');
        $this->assertStringNotContainsString(self::NOTES, $numberOnly->getContent());
        $this->assertStringNotContainsString('juan@hospital.test', $numberOnly->getContent());
    }

    public function test_track_confirm_dispute_and_survey_need_the_ticket_number_and_name(): void
    {
        [$ticket] = $this->completed();
        $body = ['ticket_number' => $ticket->ticket_number, 'requestor' => 'Juan Dela Cruz'];

        $this->postJson('/api/track/confirm', ['requestor' => 'Somebody Else'] + $body)
            ->assertNotFound()->assertJsonPath('message', 'Ticket not found.');
        $this->postJson('/api/track/confirm', ['ticket_number' => $ticket->ticket_number])
            ->assertUnprocessable()->assertJsonValidationErrors(['requestor']);
        $this->postJson('/api/track/dispute', $body)
            ->assertUnprocessable()->assertJsonValidationErrors(['reason']);

        $this->postJson('/api/track/confirm', ['requestor' => '  juan dela CRUZ '] + $body)
            ->assertOk()->assertJsonPath('result.confirmation.state', 'confirmed');
        $this->assertSame('track', $ticket->confirmations()->firstOrFail()->channel);

        $this->postJson('/api/track/survey', $body + ['ratings' => self::RATINGS])->assertCreated();
        $this->postJson('/api/track/survey', $body + ['ratings' => self::RATINGS])->assertStatus(409);
        $this->postJson('/api/track/confirm', $body)->assertStatus(409);
    }

    public function test_track_dispute_works_and_notifies_staff(): void
    {
        [$ticket] = $this->completed();

        $this->postJson('/api/track/dispute', ['ticket_number' => $ticket->ticket_number, 'requestor' => 'Juan Dela Cruz', 'reason' => 'It does not print at all'])
            ->assertOk()->assertJsonPath('result.confirmation.state', 'disputed');

        $this->assertSame('In-progress', $this->statusLabel($ticket));
        $this->assertSame('track', $ticket->confirmations()->firstOrFail()->channel);
        Mail::assertSent(TicketDisputedMail::class);
    }

    public function test_track_works_when_the_email_failed_or_there_was_none(): void
    {
        [$ticket] = $this->completed(null);

        $this->postJson('/api/track/confirm', ['ticket_number' => $ticket->ticket_number, 'requestor' => 'Juan Dela Cruz'])
            ->assertOk();
        $this->assertSame('Completed', $this->statusLabel($ticket));
    }

    public function test_track_actions_on_a_ticket_that_was_never_completed_are_refused(): void
    {
        $ticket = $this->takenTicket();

        $this->postJson('/api/track/confirm', ['ticket_number' => $ticket->ticket_number, 'requestor' => 'Juan Dela Cruz'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'This ticket is not waiting for confirmation.');
    }

    // ---------------------------------------------------------------- head close and resend

    public function test_only_a_department_head_can_close_on_the_requesters_behalf(): void
    {
        [$ticket] = $this->completed(null);

        $this->actingAsUser($this->itUser());
        $this->postJson("/api/tickets/{$ticket->id}/confirmation/close", ['reason' => 'Requester has no email'])
            ->assertForbidden()
            ->assertJsonPath('message', "Only Department Heads can close a ticket on the requester's behalf.");

        $head = $this->actingAsAdmin();
        $this->postJson("/api/tickets/{$ticket->id}/confirmation/close", ['reason' => 'no'])->assertUnprocessable()->assertJsonValidationErrors(['reason']);

        $this->postJson("/api/tickets/{$ticket->id}/confirmation/close", ['reason' => 'Requester has no email'])
            ->assertOk()
            ->assertJsonPath('result.confirmation.state', 'head_closed')
            ->assertJsonPath('result.confirmation.channel', 'head');

        $this->assertSame('Completed', $this->statusLabel($ticket));
        $this->assertDatabaseHas('ticket_date_activities', [
            'ticket_id' => $ticket->id, 'date_category_id' => 6, 'user_id' => $head->id,
            'remarks' => "Closed on the requester's behalf: Requester has no email",
        ]);

        $this->postJson("/api/tickets/{$ticket->id}/confirmation/close", ['reason' => 'Requester has no email'])
            ->assertStatus(409)->assertJsonPath('message', 'This ticket is not waiting for confirmation.');
    }

    public function test_closing_requires_authentication_and_a_waiting_ticket(): void
    {
        $ticket = $this->takenTicket();
        $this->postJson("/api/tickets/{$ticket->id}/confirmation/close", ['reason' => 'Requester has no email'])->assertUnauthorized();

        $this->actingAsAdmin();
        $this->postJson("/api/tickets/{$ticket->id}/confirmation/close", ['reason' => 'Requester has no email'])->assertStatus(409);
    }

    public function test_staff_can_resend_the_email_and_the_old_link_stops_working(): void
    {
        [$ticket, $oldToken] = $this->completed();
        Mail::fake();

        $this->postJson("/api/tickets/{$ticket->id}/confirmation/resend")
            ->assertOk()
            ->assertJsonPath('message', 'Confirmation email queued');

        $newToken = $this->emailedToken();
        $this->assertNotNull($newToken);
        $this->assertNotSame($oldToken, $newToken);
        $this->getJson("/api/confirm/{$oldToken}")->assertNotFound();
        $this->getJson("/api/confirm/{$newToken}")->assertOk();
        $this->assertSame(2, TicketMailLog::query()->count());
    }

    public function test_resent_link_uses_the_configured_portal_host_not_the_request_host(): void
    {
        [$ticket] = $this->completed();
        Mail::fake();

        $this->withHeader('Host', 'evil.example')->postJson("/api/tickets/{$ticket->id}/confirmation/resend")->assertOk();

        $token = $this->emailedToken();
        Mail::assertSent(TicketCompletionMail::class, fn ($mail) => $mail->url === 'http://portal.test/confirm/'.$token);
    }

    public function test_an_unset_portal_url_logs_a_warning(): void
    {
        config(['tickets.requestor_portal_url' => 'https://set-requestor-portal-url.invalid']);
        Log::spy();
        [$ticket] = $this->completed();
        Mail::fake();

        $this->postJson("/api/tickets/{$ticket->id}/confirmation/resend")->assertOk();

        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'REQUESTOR_PORTAL_URL'))->atLeast()->once();
    }

    public function test_resend_needs_an_email_a_waiting_ticket_and_the_right_department(): void
    {
        [$withoutEmail] = $this->completed(null);
        $this->postJson("/api/tickets/{$withoutEmail->id}/confirmation/resend")
            ->assertStatus(409)->assertJsonPath('message', 'This ticket has no requester email.');

        $notWaiting = $this->takenTicket();
        $this->postJson("/api/tickets/{$notWaiting->id}/confirmation/resend")
            ->assertStatus(409)->assertJsonPath('message', 'This ticket is not waiting for confirmation.');

        [$ticket] = $this->completed();
        $this->actingAsUser($this->engineeringUser());
        $this->postJson("/api/tickets/{$ticket->id}/confirmation/resend")->assertForbidden();
    }

    // ---------------------------------------------------------------- auto-close

    public function test_unanswered_tickets_close_themselves_after_the_configured_days(): void
    {
        [$expired] = $this->completed();
        [$recent] = $this->completed();
        TicketConfirmation::query()->where('ticket_id', $expired->id)->update(['completed_at' => now()->subDays(31), 'expires_at' => now()->subDay()]);

        $this->artisan('tickets:auto-close-confirmations')->assertSuccessful();

        $this->assertSame('Completed', $this->statusLabel($expired));
        $this->assertSame('Pending Confirmation', $this->statusLabel($recent));
        $confirmation = $expired->confirmations()->firstOrFail();
        $this->assertSame('auto_closed', $confirmation->state);
        $this->assertSame('auto', $confirmation->channel);
        $this->assertDatabaseHas('ticket_date_activities', ['ticket_id' => $expired->id, 'date_category_id' => 8, 'remarks' => 'No response within 30 days']);

        $this->artisan('tickets:auto-close-confirmations')->expectsOutput('Auto-closed 0 ticket(s).')->assertSuccessful();
        $this->assertSame(1, TicketDateActivity::query()->where('ticket_id', $expired->id)->where('date_category_id', 8)->count());
    }

    public function test_the_auto_close_period_is_configurable(): void
    {
        config(['tickets.autoclose_days' => 7]);
        [$ticket] = $this->completed();

        $this->assertEqualsWithDelta(7 * 86400, $ticket->confirmations()->firstOrFail()->completed_at->diffInSeconds($ticket->confirmations()->firstOrFail()->expires_at, true), 5);
    }

    public function test_auto_close_never_touches_answered_confirmations(): void
    {
        [$ticket, $token] = $this->completed();
        $this->postJson("/api/confirm/{$token}/dispute", ['reason' => 'Still broken after the visit'])->assertOk();
        $this->expire($ticket);

        $this->artisan('tickets:auto-close-confirmations')->assertSuccessful();

        $this->assertSame('In-progress', $this->statusLabel($ticket));
        $this->assertSame('disputed', $ticket->confirmations()->firstOrFail()->state);
    }

    // ---------------------------------------------------------------- requestor_email on submission

    private function publicPayload(array $overrides = []): array
    {
        return array_merge([
            'service_department' => $this->itDepartment()->id,
            'area_supervisor' => 'Jane Supervisor',
            'type_of_concern' => 'Hardware',
            'devices' => [['type' => 'Monitor', 'quantity' => 1]],
            'name_of_area' => 'Pharmacy',
            'remarks' => 'Screen flickers',
            'priority' => 1,
            'requestor' => 'Juan Dela Cruz',
        ], $overrides);
    }

    public function test_public_submission_stores_a_trimmed_lowercase_requester_email(): void
    {
        $this->postJson('/api/saveTicketIT', $this->publicPayload(['requestor_email' => '  Juan.DelaCruz@Hospital.TEST ']))
            ->assertCreated();

        $this->assertSame('juan.delacruz@hospital.test', Ticket::query()->latest('id')->firstOrFail()->requester_email);
    }

    public function test_the_requester_email_is_optional_and_invalid_ones_are_rejected(): void
    {
        $this->postJson('/api/saveTicketIT', $this->publicPayload())->assertCreated();
        $this->assertNull(Ticket::query()->latest('id')->firstOrFail()->requester_email);

        $this->postJson('/api/saveTicketIT', $this->publicPayload(['requestor_email' => '']))->assertCreated();
        $this->assertNull(Ticket::query()->latest('id')->firstOrFail()->requester_email);

        foreach (['not-an-email', str_repeat('a', 250).'@x.test'] as $bad) {
            $this->postJson('/api/saveTicketIT', $this->publicPayload(['requestor_email' => $bad]))
                ->assertUnprocessable()
                ->assertJsonPath('success', false)
                ->assertJsonPath('message', 'Validation failed')
                ->assertJsonStructure(['errors' => ['requestor_email']]);
        }
    }

    public function test_engineering_submission_stores_the_requester_email_and_ignores_unknown_fields(): void
    {
        $this->postJson('/api/saveTicketEngineering', [
            'service_department' => $this->engineeringDepartment()->id,
            'nature_of_work' => 'Electrical',
            'scope_of_work' => 'Replace the socket',
            'name_of_area' => 'ICU',
            'priority' => 5,
            'requestor' => 'Maria Santos',
            'requestor_email' => 'Maria@Hospital.test',
            'something_else' => 'ignored',
        ])->assertCreated();

        $this->assertSame('maria@hospital.test', Ticket::query()->latest('id')->firstOrFail()->requester_email);
    }
}
