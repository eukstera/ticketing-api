<?php

namespace Tests\Feature\Admin;

use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Group;

#[Group('phase2')]
class AdminDepartmentsTest extends AdminResourceTestCase
{
    use ChecksAdminCreate;

    protected function path(): string
    {
        return '/admin/departments';
    }

    protected function listKey(): string
    {
        return 'departments';
    }

    protected function nameField(): string
    {
        return 'name';
    }

    protected function table(): string
    {
        return 'departments';
    }

    protected function newRow(string $name, bool $active = true): Model
    {
        return DepartmentFactory::new()->create(['department' => $name, 'status' => $active]);
    }

    protected function renamePayload(string $name): array
    {
        return ['name' => $name];
    }

    protected function createPayload(string $name): array
    {
        return ['name' => $name];
    }

    public function test_deactivated_departments_leave_the_department_lookup(): void
    {
        $this->actingAsAdmin();
        $row = $this->newRow(self::MARKER.' Lookup');

        $this->deleteJson($this->rowUrl($row))->assertOk();

        $names = array_column($this->getJson('/api/lookups/departments')->json('result.departments'), 'name');
        $this->assertNotContains(self::MARKER.' Lookup', $names);
    }
}
