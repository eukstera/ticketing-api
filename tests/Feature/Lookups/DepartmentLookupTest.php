<?php

namespace Tests\Feature\Lookups;

use Database\Factories\DepartmentFactory;
use PHPUnit\Framework\Attributes\Group;
use Tests\ApiTestCase;

#[Group('phase2')]
class DepartmentLookupTest extends ApiTestCase
{
    public function test_any_authenticated_user_gets_the_active_departments(): void
    {
        $this->actingAsUser();
        DepartmentFactory::new()->create(['department' => 'Retired', 'status' => false]);

        $response = $this->getJson('/api/lookups/departments');

        $response->assertOk()->assertJsonPath('success', true);
        $departments = $response->json('result.departments');
        $this->assertEqualsCanonicalizing(['IT', 'Engineering'], array_column($departments, 'name'));
        $this->assertSame(['id', 'name'], array_keys($departments[0]));
        $this->assertIsInt($departments[0]['id']);
    }

    public function test_departments_lookup_requires_authentication(): void
    {
        $this->getJson('/api/lookups/departments')->assertUnauthorized();
    }
}
