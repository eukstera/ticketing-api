<?php

namespace Tests\Feature\Admin;

use App\Models\NatureOfWork;
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

    private function others(): NatureOfWork
    {
        return NatureOfWork::query()->where('label', 'Others')->firstOrFail();
    }

    public function test_others_cannot_be_renamed(): void
    {
        $this->actingAsAdmin();
        $others = $this->others();

        $this->patchJson($this->rowUrl($others), ['label' => 'Miscellaneous'])
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertSame('Others', $others->fresh()->label);
    }

    public function test_others_cannot_be_deactivated(): void
    {
        $this->actingAsAdmin();
        $others = $this->others();

        $this->deleteJson($this->rowUrl($others))->assertStatus(409)->assertJsonPath('success', false);
        $this->patchJson($this->rowUrl($others), ['status' => false])->assertStatus(409);

        $this->assertTrue((bool) $others->fresh()->status);
    }

    public function test_others_can_be_patched_with_unchanged_values(): void
    {
        $this->actingAsAdmin();

        $this->patchJson($this->rowUrl($this->others()), ['label' => 'Others', 'status' => true])->assertOk();
    }

    public function test_other_natures_are_not_protected(): void
    {
        $this->actingAsAdmin();
        $row = $this->newRow(self::MARKER.' Plain');

        $this->patchJson($this->rowUrl($row), ['label' => self::MARKER.' Renamed'])->assertOk();
        $this->deleteJson($this->rowUrl($row))->assertOk();
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
