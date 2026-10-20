<?php

namespace Tests\Feature\Admin;

use Database\Factories\TypeOfConcernFactory;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Group;

#[Group('phase2')]
class AdminConcernTypesTest extends AdminResourceTestCase
{
    use ChecksAdminCreate;

    protected function path(): string
    {
        return '/admin/concern-types';
    }

    protected function listKey(): string
    {
        return 'concern_types';
    }

    protected function nameField(): string
    {
        return 'label';
    }

    protected function table(): string
    {
        return 'type_of_concern';
    }

    protected function newRow(string $name, bool $active = true): Model
    {
        return TypeOfConcernFactory::new()->create(['label' => $name, 'status' => $active]);
    }

    protected function renamePayload(string $name): array
    {
        return ['label' => $name];
    }

    protected function createPayload(string $name): array
    {
        return ['label' => $name];
    }

    public function test_deactivated_concern_types_leave_the_lookup(): void
    {
        $this->actingAsAdmin();
        $row = $this->newRow(self::MARKER.' Lookup');

        $this->deleteJson($this->rowUrl($row))->assertOk();

        $labels = array_column($this->getJson('/api/lookups/concern-types')->json('result.concern_types'), 'label');
        $this->assertNotContains(self::MARKER.' Lookup', $labels);

        $this->patchJson($this->rowUrl($row), ['status' => true])->assertOk();
        $labels = array_column($this->getJson('/api/lookups/concern-types')->json('result.concern_types'), 'label');
        $this->assertContains(self::MARKER.' Lookup', $labels);
    }
}
