<?php

namespace Tests\Feature\Admin;

use Database\Factories\NatureOfWorkFactory;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Group;

#[Group('phase2')]
class AdminNatureOfWorkTest extends AdminResourceTestCase
{
    use ChecksAdminCreate;

    protected function path(): string
    {
        return '/admin/nature-of-work';
    }

    protected function listKey(): string
    {
        return 'nature_of_work';
    }

    protected function nameField(): string
    {
        return 'label';
    }

    protected function table(): string
    {
        return 'nature_of_work';
    }

    protected function newRow(string $name, bool $active = true): Model
    {
        return NatureOfWorkFactory::new()->create(['label' => $name, 'status' => $active]);
    }

    protected function renamePayload(string $name): array
    {
        return ['label' => $name];
    }

    protected function createPayload(string $name): array
    {
        return ['label' => $name];
    }

    public function test_deactivated_natures_leave_the_lookup(): void
    {
        $this->actingAsAdmin();
        $row = $this->newRow(self::MARKER.' Lookup');

        $this->deleteJson($this->rowUrl($row))->assertOk();

        $labels = array_column($this->getJson('/api/lookups/nature-of-work')->json('result.nature_of_work'), 'label');
        $this->assertNotContains(self::MARKER.' Lookup', $labels);
    }
}
