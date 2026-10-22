<?php

namespace Tests\Feature\Reports;

use App\Models\ReportSignatory;
use Database\Factories\ReportSignatoryFactory;
use Database\Seeders\ReferenceDataSeeder;
use Tests\ApiTestCase;

class ReportSignatoryTest extends ApiTestCase
{
    public function test_routes_require_authentication(): void
    {
        $row = ReportSignatoryFactory::new()->create();

        $this->getJson('/api/report-signatories')->assertUnauthorized();
        $this->getJson('/api/admin/report-signatories')->assertUnauthorized();
        $this->postJson('/api/admin/report-signatories', [])->assertUnauthorized();
        $this->patchJson("/api/admin/report-signatories/{$row->id}", [])->assertUnauthorized();
        $this->deleteJson("/api/admin/report-signatories/{$row->id}")->assertUnauthorized();
    }

    public function test_any_user_lists_only_active_signatories_default_first(): void
    {
        $this->actingAsUser($this->itUser());
        ReportSignatoryFactory::new()->create(['name' => 'ZED', 'title' => 'Chief']);
        ReportSignatoryFactory::new()->create(['name' => 'ABEL', 'title' => 'Head']);
        ReportSignatoryFactory::new()->default()->create(['name' => 'MIA', 'title' => 'Director']);
        ReportSignatoryFactory::new()->inactive()->create(['name' => 'GONE', 'title' => 'Former']);

        $response = $this->getJson('/api/report-signatories')->assertOk()->assertJsonPath('success', true);

        $this->assertSame(['MIA', 'ABEL', 'ZED'], array_column($response->json('result.signatories'), 'name'));
        $response->assertJsonPath('result.signatories.0.is_default', true)
            ->assertJsonStructure(['result' => ['signatories' => [['id', 'name', 'title', 'is_default', 'status']]]]);
    }

    public function test_non_admins_cannot_manage_signatories(): void
    {
        $this->actingAsUser($this->itUser());
        $row = ReportSignatoryFactory::new()->create();

        $this->getJson('/api/admin/report-signatories')->assertForbidden();
        $this->postJson('/api/admin/report-signatories', ['name' => 'A', 'title' => 'B'])->assertForbidden();
        $this->patchJson("/api/admin/report-signatories/{$row->id}", ['title' => 'X'])->assertForbidden();
        $this->deleteJson("/api/admin/report-signatories/{$row->id}")->assertForbidden();
    }

    public function test_admin_can_create_and_list_signatories(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/report-signatories', ['name' => 'Ana Cruz', 'title' => 'ICT Head'])
            ->assertCreated()
            ->assertJsonPath('result.signatory.name', 'Ana Cruz')
            ->assertJsonPath('result.signatory.is_default', false)
            ->assertJsonPath('result.signatory.status', true);

        $this->getJson('/api/admin/report-signatories?search=Ana')
            ->assertOk()
            ->assertJsonPath('result.signatories.total', 1);
    }

    public function test_create_validates_required_fields_and_lengths(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/report-signatories', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'title']);

        $this->postJson('/api/admin/report-signatories', ['name' => str_repeat('a', 256), 'title' => 'T'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_name_and_title_must_be_unique_together_ignoring_case(): void
    {
        $this->actingAsAdmin();
        ReportSignatoryFactory::new()->create(['name' => 'Ana Cruz', 'title' => 'ICT Head']);

        $this->postJson('/api/admin/report-signatories', ['name' => 'ANA CRUZ', 'title' => 'ict head'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->postJson('/api/admin/report-signatories', ['name' => 'Ana Cruz', 'title' => 'Director'])
            ->assertCreated();
    }

    public function test_making_a_signatory_default_clears_the_previous_default(): void
    {
        $this->actingAsAdmin();
        $old = ReportSignatoryFactory::new()->default()->create();
        $new = ReportSignatoryFactory::new()->create();

        $this->patchJson("/api/admin/report-signatories/{$new->id}", ['is_default' => true])
            ->assertOk()
            ->assertJsonPath('result.signatory.is_default', true);

        $this->assertFalse($old->refresh()->is_default);
        $this->assertSame(1, ReportSignatory::query()->where('is_default', true)->count());
    }

    public function test_creating_a_default_signatory_clears_the_previous_default(): void
    {
        $this->actingAsAdmin();
        $old = ReportSignatoryFactory::new()->default()->create();

        $this->postJson('/api/admin/report-signatories', ['name' => 'New', 'title' => 'Boss', 'is_default' => true])
            ->assertCreated();

        $this->assertFalse($old->refresh()->is_default);
    }

    public function test_an_inactive_signatory_cannot_be_created_as_default(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/report-signatories', [
            'name' => 'New',
            'title' => 'Boss',
            'is_default' => true,
            'status' => false,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The default signatory must be active.');

        $this->assertDatabaseCount('report_signatories', 0);
    }

    public function test_reference_seeder_adds_the_default_signatory_once(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(ReferenceDataSeeder::class);

        $this->assertDatabaseCount('report_signatories', 1);
        $this->assertDatabaseHas('report_signatories', [
            'name' => 'JOSEFA SOCORRO MOLON PATAWARAN',
            'title' => 'CMT II – ICTS',
            'is_default' => true,
            'status' => true,
        ]);
    }

    public function test_reference_seeder_keeps_an_existing_default_and_admin_edits(): void
    {
        $existing = ReportSignatoryFactory::new()->default()->create(['name' => 'Ana Cruz', 'title' => 'Head']);

        $this->seed(ReferenceDataSeeder::class);
        ReportSignatory::query()->where('name', 'JOSEFA SOCORRO MOLON PATAWARAN')->update(['status' => false]);
        $this->seed(ReferenceDataSeeder::class);

        $this->assertTrue($existing->refresh()->is_default);
        $this->assertDatabaseCount('report_signatories', 2);
        $this->assertDatabaseHas('report_signatories', ['name' => 'JOSEFA SOCORRO MOLON PATAWARAN', 'is_default' => false, 'status' => false]);
    }

    public function test_default_cannot_be_deactivated_or_unset(): void
    {
        $this->actingAsAdmin();
        $default = ReportSignatoryFactory::new()->default()->create();

        $this->deleteJson("/api/admin/report-signatories/{$default->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Set another signatory as default first.');

        $this->patchJson("/api/admin/report-signatories/{$default->id}", ['status' => false])
            ->assertUnprocessable();

        $this->patchJson("/api/admin/report-signatories/{$default->id}", ['is_default' => false])
            ->assertUnprocessable();

        $this->assertTrue($default->refresh()->status);
        $this->assertTrue($default->is_default);
    }

    public function test_delete_soft_deactivates_a_non_default_signatory(): void
    {
        $this->actingAsAdmin();
        $row = ReportSignatoryFactory::new()->create();

        $this->deleteJson("/api/admin/report-signatories/{$row->id}")
            ->assertOk()
            ->assertJsonPath('result.signatory.status', false);

        $this->assertDatabaseHas('report_signatories', ['id' => $row->id, 'status' => false]);
    }

    public function test_missing_signatory_returns_not_found(): void
    {
        $this->actingAsAdmin();

        $this->patchJson('/api/admin/report-signatories/9999', ['title' => 'X'])->assertNotFound();
        $this->deleteJson('/api/admin/report-signatories/9999')->assertNotFound();
    }
}
