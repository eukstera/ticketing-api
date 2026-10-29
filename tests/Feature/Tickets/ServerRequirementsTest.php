<?php

namespace Tests\Feature\Tickets;

use App\Models\User;
use App\Services\PhpRequirements;
use Database\Factories\TicketFactory;
use Tests\ApiTestCase;

class ServerRequirementsTest extends ApiTestCase
{
    /**
     * Stands in for a server whose PHP lacks the given extensions.
     *
     * @param  list<string>  $absent
     */
    private function serverWithout(array $absent): void
    {
        $this->app->instance(PhpRequirements::class, new class($absent) extends PhpRequirements
        {
            /** @param list<string> $absent */
            public function __construct(private array $absent) {}

            public function missing(array $extensions): array
            {
                return array_values(array_intersect($extensions, $this->absent));
            }
        });
    }

    private function itTicketId(): int
    {
        return TicketFactory::new()->forDepartment($this->itDepartment())->open()->create()->id;
    }

    public function test_a_missing_gd_extension_gives_a_clear_message_for_the_pdf_not_a_generic_error(): void
    {
        $this->actingAsUser($this->itUser());
        $id = $this->itTicketId();
        $this->serverWithout(['gd']);

        $this->getJson("/api/tickets/{$id}/job-order?format=pdf")
            ->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'PDF generation is not available on this server (missing PHP extension: gd). Ask the system administrator to enable it.');
    }

    public function test_the_excel_job_order_does_not_need_gd(): void
    {
        $this->actingAsUser($this->itUser());
        $id = $this->itTicketId();
        $this->serverWithout(['gd']);

        $this->get("/api/tickets/{$id}/job-order?format=xlsx")->assertOk();
    }

    public function test_a_missing_zip_extension_is_reported_for_the_excel_job_order(): void
    {
        $this->actingAsUser($this->itUser());
        $id = $this->itTicketId();
        $this->serverWithout(['zip']);

        $this->getJson("/api/tickets/{$id}/job-order?format=xlsx")
            ->assertStatus(503)
            ->assertJsonPath('message', 'Excel generation is not available on this server (missing PHP extension: zip). Ask the system administrator to enable it.');
    }

    public function test_the_consolidated_export_reports_a_missing_extension(): void
    {
        $this->actingAsUser(User::factory()->inDepartment($this->itDepartment())->encoder()->create());
        $this->serverWithout(['gd', 'zip']);

        $this->getJson('/api/reports/consolidated/export')
            ->assertStatus(503)
            ->assertJsonPath('message', 'The Excel export is not available on this server (missing PHP extension: gd, zip). Ask the system administrator to enable it.');
    }

    public function test_the_server_check_command_passes_here_and_fails_when_an_extension_is_missing(): void
    {
        $this->artisan('tickets:check-server')->assertSuccessful();

        $this->serverWithout(['gd']);

        $this->artisan('tickets:check-server')
            ->expectsOutputToContain('MISSING')
            ->expectsOutputToContain('Missing PHP extension(s): gd')
            ->assertFailed();
    }
}
