<?php

namespace Tests\Feature\Lookups;

use App\Models\Designation;
use Tests\ApiTestCase;

class DesignationTest extends ApiTestCase
{
    public function test_designations_are_public_and_filtered_by_department(): void
    {
        $response = $this->getJson('/api/designations?department_id=1');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'result.designations')
            ->assertJsonPath('result.designations.0.designation', 'Tech Support')
            ->assertJsonPath('result.designations.0.department_id', 1)
            ->assertJsonPath('result.designations.0.status', true);
    }

    public function test_department_id_is_required_and_must_exist(): void
    {
        $this->getJson('/api/designations')
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['department_id']);

        $this->getJson('/api/designations?department_id=9999')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['department_id']);
    }

    public function test_deactivated_designations_are_hidden(): void
    {
        Designation::query()->where('department_id', 1)->update(['status' => false]);

        $this->getJson('/api/designations?department_id=1')
            ->assertOk()
            ->assertJsonCount(0, 'result.designations');
    }

    public function test_admin_can_create_a_designation(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/designations', ['department_id' => 1, 'designation' => 'Network Engineer'])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('result.designation.designation', 'Network Engineer');

        $this->assertDatabaseHas('designations', ['department_id' => 1, 'designation' => 'Network Engineer']);
    }

    public function test_designation_creation_is_validated(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/designations', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['department_id', 'designation']);
    }

    public function test_duplicate_designation_names_are_rejected_case_insensitively(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/designations', ['department_id' => 1, 'designation' => 'TECH SUPPORT'])
            ->assertUnprocessable();

        $this->assertSame(1, Designation::where('department_id', 1)->count());
    }

    public function test_non_admin_cannot_create_a_designation(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/designations', ['department_id' => 1, 'designation' => 'Hacker'])
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->assertDatabaseMissing('designations', ['designation' => 'Hacker']);
    }

    public function test_anonymous_users_cannot_create_a_designation(): void
    {
        $this->postJson('/api/designations', ['department_id' => 1, 'designation' => 'Hacker'])
            ->assertUnauthorized();
    }
}
