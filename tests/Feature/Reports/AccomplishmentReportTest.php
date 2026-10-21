<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use Database\Factories\CommentFactory;
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

    public function test_report_filters_by_comment_date_not_ticket_date(): void
    {
        $user = $this->actingAsUser($this->itUser());
        $oldTicket = TicketFactory::new()->forDepartment($this->itDepartment())->requestedAt('2026-01-01 08:00:00')->create();
        CommentFactory::new()->state(['ticket_id' => $oldTicket->id, 'user_id' => $user->id, 'comment' => 'Followed up on old ticket'])
            ->create(['created_at' => '2026-05-03 09:00:00']);

        $response = $this->get('/api/reports/accomplishment?'.http_build_query($this->range()));

        $response->assertOk();
        $this->assertStringContainsString(
            'Followed up on old ticket',
            $this->docxText($response->baseResponse->getFile()->getPathname())
        );
    }

    public function test_report_excludes_other_users_comments(): void
    {
        $user = $this->actingAsUser($this->itUser());
        $other = User::factory()->inDepartment($this->itDepartment())->create();
        $ticket = TicketFactory::new()->forDepartment($this->itDepartment())->create();
        CommentFactory::new()->state(['ticket_id' => $ticket->id, 'user_id' => $user->id, 'comment' => 'Mine'])
            ->create(['created_at' => '2026-05-03 09:00:00']);
        CommentFactory::new()->state(['ticket_id' => $ticket->id, 'user_id' => $other->id, 'comment' => 'Theirs'])
            ->create(['created_at' => '2026-05-03 09:00:00']);

        $text = $this->docxText(
            $this->get('/api/reports/accomplishment?'.http_build_query($this->range()))
                ->baseResponse->getFile()->getPathname()
        );

        $this->assertStringContainsString('Mine', $text);
        $this->assertStringNotContainsString('Theirs', $text);
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
