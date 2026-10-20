<?php

namespace Tests\Feature\Lookups;

use App\Models\NatureOfWork;
use App\Models\Priority;
use App\Models\TypeOfConcern;
use Tests\ApiTestCase;

class LookupTest extends ApiTestCase
{
    public function test_priorities_default_to_the_callers_department(): void
    {
        $this->actingAsUser($this->itUser());

        $response = $this->getJson('/api/priority');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(4, 'result.priorities')
            ->assertJsonPath('result.priorities.0.level', 'Urgent & IHOMIS Related')
            ->assertJsonPath('result.priorities.0.sla_days', 1)
            ->assertJsonPath('result.priorities.0.department_id', 1);
        $this->assertIsInt($response->json('result.priorities.0.sla_days'));
        $this->assertIsInt($response->json('result.priorities.0.department_id'));
    }

    public function test_priorities_can_be_requested_for_another_department(): void
    {
        $this->actingAsUser($this->itUser());

        $this->getJson('/api/priority?department_id=2')
            ->assertOk()
            ->assertJsonCount(3, 'result.priorities')
            ->assertJsonPath('result.priorities.0.department_id', 2);
    }

    public function test_deactivated_priorities_are_hidden(): void
    {
        $this->actingAsUser($this->itUser());
        Priority::query()->where('department_id', 1)->where('level', 'Long Term')->update(['status' => false]);

        $this->getJson('/api/priority')->assertOk()->assertJsonCount(3, 'result.priorities');
    }

    public function test_priority_requires_authentication(): void
    {
        $this->getJson('/api/priority')->assertUnauthorized();
    }

    public function test_concern_types_list_active_rows_only(): void
    {
        $this->actingAsUser();
        TypeOfConcern::query()->where('label', 'Printer')->update(['status' => false]);

        $response = $this->getJson('/api/lookups/concern-types');

        $response->assertOk()->assertJsonPath('success', true);
        $labels = collect($response->json('result.concern_types'))->pluck('label')->all();
        $this->assertEqualsCanonicalizing(['Hardware', 'Software'], $labels);
        $this->assertSame(['id', 'label'], array_keys($response->json('result.concern_types.0')));
    }

    public function test_nature_of_work_lists_active_rows_only(): void
    {
        $this->actingAsUser();
        NatureOfWork::query()->where('label', 'Electrical')->update(['status' => false]);

        $labels = collect($this->getJson('/api/lookups/nature-of-work')->assertOk()->json('result.nature_of_work'))
            ->pluck('label')->all();

        $this->assertSame(['Others'], $labels);
    }

    public function test_lookups_require_authentication(): void
    {
        $this->getJson('/api/lookups/concern-types')->assertUnauthorized();
        $this->getJson('/api/lookups/nature-of-work')->assertUnauthorized();
    }

    public function test_roles_are_listed_for_any_authenticated_user(): void
    {
        $this->actingAsUser();

        $response = $this->getJson('/api/roles');

        $response->assertOk();
        $names = collect($response->json('result.roles'))->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['Normal User', 'Department Head'], $names);
    }
}
