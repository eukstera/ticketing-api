<?php

namespace Tests\Feature\Reports;

use App\Models\TicketConfirmation;
use App\Models\TicketDateActivity;
use App\Models\User;
use Database\Factories\CommentFactory;
use Database\Factories\ReportSignatoryFactory;
use Database\Factories\TicketConfirmationFactory;
use Database\Factories\TicketFactory;
use Tests\ApiTestCase;

class AccomplishmentReportTest extends ApiTestCase
{
    /**
     * @return array<string, string>
     */
    private function range(): array
    {
        return ['date_from' => '2026-05-01', 'date_to' => '2026-05-07'];
    }

    public function test_user_can_download_their_own_report(): void
    {
        $user = $this->actingAsUser($this->itUser());
        $ticket = TicketFactory::new()->forDepartment($this->itDepartment())->create();
        CommentFactory::new()->state(['ticket_id' => $ticket->id, 'user_id' => $user->id])
            ->create(['created_at' => '2026-05-03 09:00:00']);

        $response = $this->get('/api/reports/accomplishment?'.http_build_query($this->range()));

        $response->assertOk();
        $this->assertStringContainsString('.docx', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString($user->last_name, $response->headers->get('Content-Disposition'));
    }

    public function test_report_requires_authentication(): void
    {
        $this->getJson('/api/reports/accomplishment?'.http_build_query($this->range()))->assertUnauthorized();
    }

    public function test_non_admin_cannot_export_another_users_report(): void
    {
        $this->actingAsUser($this->itUser());
        $other = $this->itUser();

        $this->getJson('/api/reports/accomplishment?'.http_build_query($this->range() + ['user_id' => $other->id]))
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', "Only Department Heads can export another user's report.");
    }

    public function test_non_admin_can_pass_their_own_id(): void
    {
        $user = $this->actingAsUser($this->itUser());

        $this->get('/api/reports/accomplishment?'.http_build_query($this->range() + ['user_id' => $user->id]))
            ->assertOk();
    }

    public function test_admin_can_export_another_users_report(): void
    {
        $this->actingAsAdmin();
        $other = $this->itUser();

        $this->get('/api/reports/accomplishment?'.http_build_query($this->range() + ['user_id' => $other->id]))
            ->assertOk();
    }

    public function test_report_validates_the_date_range(): void
    {
        $this->actingAsUser($this->itUser());

        $this->getJson('/api/reports/accomplishment')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_from', 'date_to']);

        $this->getJson('/api/reports/accomplishment?date_from=2026-05-10&date_to=2026-05-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_to']);
    }

    public function test_report_for_an_empty_range_still_downloads(): void
    {
        $this->actingAsUser($this->itUser());

        $this->get('/api/reports/accomplishment?'.http_build_query($this->range()))->assertOk();
    }

    public function test_report_covers_a_range_spanning_two_months(): void
    {
        $this->actingAsUser($this->itUser());

        $this->get('/api/reports/accomplishment?date_from=2026-04-28&date_to=2026-05-03')->assertOk();
    }

    public function test_unknown_user_id_is_rejected(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/reports/accomplishment?'.http_build_query($this->range() + ['user_id' => 9999]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user_id']);
    }

    private function completion(User $by, string $notes, string $at, array $ticketAttributes = [], string $state = TicketConfirmation::PENDING): TicketConfirmation
    {
        $ticket = TicketFactory::new()->forDepartment($this->itDepartment())->assigned($by)->create($ticketAttributes);

        return TicketConfirmationFactory::new()->completedAt($at)->create([
            'ticket_id' => $ticket->id,
            'completed_by' => $by->id,
            'completion_notes' => $notes,
            'state' => $state,
        ]);
    }

    public function test_action_taken_is_the_completion_notes_dated_by_the_completion(): void
    {
        $user = $this->actingAsUser($this->itUser());
        $confirmation = $this->completion($user, 'Replaced the faulty power supply', '2026-05-03 09:00:00');
        $ticket = $confirmation->ticket;
        TicketDateActivity::query()->where('ticket_id', $ticket->id)->where('date_category_id', 1)->update(['requestor' => 'Juan Dela Cruz']);

        $text = $this->reportText();

        $this->assertStringContainsString('Replaced the faulty power supply', $text);
        $this->assertStringContainsString('J.O.#'.$ticket->ticket_number, $text);
        $this->assertStringContainsString('Requested by Juan Dela Cruz', $text);
    }

    public function test_completion_outside_the_range_is_excluded(): void
    {
        $user = $this->actingAsUser($this->itUser());
        $this->completion($user, 'Completed in April', '2026-04-20 09:00:00');

        $this->assertStringNotContainsString('Completed in April', $this->reportText());
    }

    public function test_comments_are_not_action_taken_once_a_ticket_has_a_completion(): void
    {
        $user = $this->actingAsUser($this->itUser());
        $confirmation = $this->completion($user, 'Reseated the memory modules', '2026-05-03 09:00:00');
        CommentFactory::new()->state(['ticket_id' => $confirmation->ticket_id, 'user_id' => $user->id, 'comment' => 'Just a chat comment'])
            ->create(['created_at' => '2026-05-03 08:00:00']);

        $text = $this->reportText();

        $this->assertStringContainsString('Reseated the memory modules', $text);
        $this->assertStringNotContainsString('Just a chat comment', $text);
    }

    public function test_only_the_latest_completion_of_a_disputed_ticket_counts(): void
    {
        $user = $this->actingAsUser($this->itUser());
        $first = $this->completion($user, 'First attempt at the fix', '2026-05-02 09:00:00', [], TicketConfirmation::DISPUTED);
        TicketConfirmationFactory::new()->completedAt('2026-05-05 09:00:00')->create([
            'ticket_id' => $first->ticket_id,
            'completed_by' => $user->id,
            'completion_notes' => 'Second and final fix',
        ]);

        $text = $this->reportText();

        $this->assertStringContainsString('Second and final fix', $text);
        $this->assertStringNotContainsString('First attempt at the fix', $text);
    }

    public function test_a_completion_that_stands_disputed_is_excluded(): void
    {
        $user = $this->actingAsUser($this->itUser());
        $this->completion($user, 'Disputed by the requester', '2026-05-03 09:00:00', [], TicketConfirmation::DISPUTED);

        $this->assertStringNotContainsString('Disputed by the requester', $this->reportText());
    }

    public function test_report_excludes_other_users_completions(): void
    {
        $user = $this->actingAsUser($this->itUser());
        $other = User::factory()->inDepartment($this->itDepartment())->create();
        $this->completion($user, 'Mine', '2026-05-03 09:00:00');
        $this->completion($other, 'Theirs', '2026-05-03 09:00:00');

        $text = $this->reportText();

        $this->assertStringContainsString('Mine', $text);
        $this->assertStringNotContainsString('Theirs', $text);
    }

    public function test_tickets_completed_before_completion_notes_still_use_their_comments(): void
    {
        $user = $this->actingAsUser($this->itUser());
        $legacy = TicketFactory::new()->forDepartment($this->itDepartment())->completed($user)->create();
        CommentFactory::new()->state(['ticket_id' => $legacy->id, 'user_id' => $user->id, 'comment' => 'Old style action taken'])
            ->create(['created_at' => '2026-05-03 09:00:00']);
        $inProgress = TicketFactory::new()->forDepartment($this->itDepartment())->assigned($user)->create();
        CommentFactory::new()->state(['ticket_id' => $inProgress->id, 'user_id' => $user->id, 'comment' => 'Working on it'])
            ->create(['created_at' => '2026-05-03 09:00:00']);

        $text = $this->reportText();

        $this->assertStringContainsString('Old style action taken', $text);
        $this->assertStringNotContainsString('Working on it', $text);
    }

    private function reportText(array $extra = []): string
    {
        return $this->docxText(
            $this->get('/api/reports/accomplishment?'.http_build_query($this->range() + $extra))
                ->assertOk()->baseResponse->getFile()->getPathname()
        );
    }

    public function test_report_falls_back_to_the_built_in_signatory(): void
    {
        $this->actingAsUser($this->itUser());

        $this->assertStringContainsString('JOSEFA SOCORRO MOLON PATAWARAN', $this->reportText());
    }

    public function test_report_uses_the_saved_default_signatory(): void
    {
        $this->actingAsUser($this->itUser());
        ReportSignatoryFactory::new()->default()->create(['name' => 'Default Person', 'title' => 'Default Title']);

        $text = $this->reportText();

        $this->assertStringContainsString('DEFAULT PERSON', $text);
        $this->assertStringContainsString('Default Title', $text);
        $this->assertStringNotContainsString('JOSEFA', $text);
    }

    public function test_report_uses_the_chosen_signatory_over_the_default(): void
    {
        $this->actingAsUser($this->itUser());
        ReportSignatoryFactory::new()->default()->create(['name' => 'Default Person', 'title' => 'Default Title']);
        $chosen = ReportSignatoryFactory::new()->create(['name' => 'Chosen Person', 'title' => 'Chosen Title']);

        $text = $this->reportText(['signatory_id' => $chosen->id]);

        $this->assertStringContainsString('CHOSEN PERSON', $text);
        $this->assertStringContainsString('Chosen Title', $text);
        $this->assertStringNotContainsString('DEFAULT PERSON', $text);
    }

    public function test_adhoc_signatory_overrides_a_chosen_one(): void
    {
        $this->actingAsUser($this->itUser());
        $chosen = ReportSignatoryFactory::new()->create(['name' => 'Chosen Person', 'title' => 'Chosen Title']);

        $text = $this->reportText([
            'signatory_id' => $chosen->id,
            'signatory_name' => 'One Off',
            'signatory_title' => 'Acting Head',
        ]);

        $this->assertStringContainsString('ONE OFF', $text);
        $this->assertStringContainsString('Acting Head', $text);
        $this->assertStringNotContainsString('CHOSEN PERSON', $text);
    }

    public function test_inactive_default_signatory_is_ignored(): void
    {
        $this->actingAsUser($this->itUser());
        ReportSignatoryFactory::new()->default()->inactive()->create(['name' => 'Retired Person', 'title' => 'Old']);

        $this->assertStringContainsString('JOSEFA', $this->reportText());
    }

    public function test_signatory_name_and_title_must_come_together(): void
    {
        $this->actingAsUser($this->itUser());

        $this->getJson('/api/reports/accomplishment?'.http_build_query($this->range() + ['signatory_name' => 'Only Name']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['signatory_title']);

        $this->getJson('/api/reports/accomplishment?'.http_build_query($this->range() + ['signatory_title' => 'Only Title']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['signatory_name']);
    }

    public function test_unknown_or_inactive_signatory_id_is_rejected(): void
    {
        $this->actingAsUser($this->itUser());
        $inactive = ReportSignatoryFactory::new()->inactive()->create();

        foreach ([9999, $inactive->id] as $id) {
            $this->getJson('/api/reports/accomplishment?'.http_build_query($this->range() + ['signatory_id' => $id]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['signatory_id']);
        }
    }

    public function test_download_has_docx_content_type_and_a_safe_filename(): void
    {
        $user = $this->actingAsUser($this->itUser());
        $user->update(['last_name' => 'De/La:Cruz']);

        $response = $this->get('/api/reports/accomplishment?'.http_build_query($this->range()))->assertOk();

        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            $response->headers->get('Content-Type')
        );
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringNotContainsString('/', $disposition);
        $this->assertStringContainsString('Report.docx', $disposition);
    }

    /**
     * Extracts the plain text of a generated .docx.
     */
    private function docxText(string $path): string
    {
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'Generated report is not a valid .docx');
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();

        return html_entity_decode(strip_tags($xml));
    }
}
