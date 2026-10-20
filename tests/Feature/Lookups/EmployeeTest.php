<?php

namespace Tests\Feature\Lookups;

use App\Models\User;
use Tests\ApiTestCase;

class EmployeeTest extends ApiTestCase
{
    public function test_employees_default_to_the_callers_department(): void
    {
        $caller = $this->actingAsUser($this->itUser());
        $colleague = $this->itUser();
        $engineer = $this->engineeringUser();

        $response = $this->getJson('/api/employees');

        $response->assertOk()->assertJsonPath('success', true);
        $ids = collect($response->json('result.users'))->pluck('id');
        $this->assertTrue($ids->contains($caller->id));
        $this->assertTrue($ids->contains($colleague->id));
        $this->assertFalse($ids->contains($engineer->id));
    }

    public function test_employees_can_be_filtered_by_department(): void
    {
        $this->actingAsUser($this->itUser());
        $engineer = $this->engineeringUser();

        $ids = collect($this->getJson('/api/employees?department_id=2')->assertOk()->json('result.users'))
            ->pluck('id');

        $this->assertSame([$engineer->id], $ids->all());
    }

    public function test_employee_rows_do_not_expose_email_or_password(): void
    {
        $this->actingAsUser($this->itUser());
        $this->itUser();

        foreach ($this->getJson('/api/employees')->assertOk()->json('result.users') as $row) {
            $this->assertArrayNotHasKey('email', $row);
            $this->assertArrayNotHasKey('password', $row);
            $this->assertArrayHasKey('first_name', $row);
        }
    }

    public function test_deactivated_users_are_excluded(): void
    {
        $this->actingAsUser($this->itUser());
        $gone = User::factory()->inDepartment($this->itDepartment())->inactive()->create();

        $ids = collect($this->getJson('/api/employees')->json('result.users'))->pluck('id');

        $this->assertFalse($ids->contains($gone->id));
    }

    public function test_unknown_department_is_rejected(): void
    {
        $this->actingAsUser($this->itUser());

        $this->getJson('/api/employees?department_id=9999')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['department_id']);
    }

    public function test_employees_require_authentication(): void
    {
        $this->getJson('/api/employees')->assertUnauthorized();
    }
}
