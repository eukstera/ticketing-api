<?php

namespace Tests\Feature\Admin;

use Database\Factories\PriorityFactory;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Group;

#[Group('phase2')]
class AdminPrioritiesTest extends AdminResourceTestCase
{
    use ChecksAdminCreate;

    protected function path(): string
    {
        return '/admin/priorities';
    }

    protected function listKey(): string
    {
        return 'priorities';
    }

    protected function nameField(): string
    {
        return 'level';
    }

    protected function table(): string
    {
        return 'priority';
    }

    protected function newRow(string $name, bool $active = true, int $departmentId = 1): Model
    {
        return PriorityFactory::new()->slaDays(3)->create([
            'level' => $name,
            'department_id' => $departmentId,
            'status' => $active,
        ]);
    }

    protected function renamePayload(string $name): array
    {
        return ['level' => $name];
    }

    protected function createPayload(string $name): array
    {
        return ['level' => $name, 'sla_days' => 4, 'department_id' => 1];
    }

    public function test_rows_use_the_documented_shape_with_integer_sla_days(): void
    {
        $this->actingAsAdmin();
        $row = $this->newRow(self::MARKER.' Shape', true, 2);

        $found = collect($this->listRows('search='.self::MARKER))->firstWhere('id', $row->id);

        $this->assertSame(['id', 'level', 'sla_days', 'department_id', 'status'], array_keys($found));
        $this->assertSame(3, $found['sla_days']);
        $this->assertSame(2, $found['department_id']);
    }

    public function test_list_can_be_filtered_by_department(): void
    {
        $this->actingAsAdmin();
        $this->newRow(self::MARKER.' IT', true, 1);
        $this->newRow(self::MARKER.' Eng', true, 2);

        $this->assertSame([self::MARKER.' Eng'], $this->listNames('search='.self::MARKER.'&department_id=2'));
    }

    public function test_sla_days_must_be_a_non_negative_integer(): void
    {
        $this->actingAsAdmin();
        $row = $this->newRow(self::MARKER.' Sla');

        foreach ([-1, 'abc', 1.5] as $invalid) {
            $this->patchJson($this->rowUrl($row), ['sla_days' => $invalid])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['sla_days']);
            $this->postJson($this->url(), array_merge($this->createPayload('X'), ['sla_days' => $invalid]))
                ->assertUnprocessable();
        }

        $this->patchJson($this->rowUrl($row), ['sla_days' => 0])->assertOk();
        $this->assertDatabaseHas('priority', ['id' => $row->id, 'sla_days' => 0]);
    }

    public function test_sla_days_updates_the_stored_value(): void
    {
        $this->actingAsAdmin();
        $row = $this->newRow(self::MARKER.' Sla');

        $this->patchJson($this->rowUrl($row), ['sla_days' => 9])->assertOk();

        $this->assertDatabaseHas('priority', ['id' => $row->id, 'sla_days' => 9]);
    }

    public function test_create_requires_an_existing_department(): void
    {
        $this->actingAsAdmin();

        $this->postJson($this->url(), ['level' => 'X', 'sla_days' => 1, 'department_id' => 9999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['department_id']);
    }

    public function test_levels_are_unique_within_a_department_case_insensitively(): void
    {
        $this->actingAsAdmin();
        $this->newRow('Critical', true, 1);
        $row = $this->newRow('Routine', true, 1);

        $this->patchJson($this->rowUrl($row), ['level' => 'CRITICAL'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['level']);
        $this->postJson($this->url(), ['level' => 'critical', 'sla_days' => 1, 'department_id' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['level']);
        $this->patchJson($this->rowUrl($row), ['level' => 'Routine', 'sla_days' => 7])->assertOk();
    }

    public function test_the_same_level_is_allowed_in_another_department(): void
    {
        $this->actingAsAdmin();
        $this->newRow('Critical', true, 1);

        $this->postJson($this->url(), ['level' => 'Critical', 'sla_days' => 1, 'department_id' => 2])
            ->assertCreated();
    }

    public function test_moving_to_a_department_that_already_has_the_level_is_rejected(): void
    {
        $this->actingAsAdmin();
        $this->newRow('Critical', true, 1);
        $row = $this->newRow('critical', true, 2);

        $this->patchJson($this->rowUrl($row), ['department_id' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['department_id']);

        $this->assertDatabaseHas('priority', ['id' => $row->id, 'department_id' => 2]);
    }

    public function test_deactivated_priorities_leave_the_priority_lookup(): void
    {
        $this->actingAsAdmin();
        $row = $this->newRow(self::MARKER.' Lookup', true, 1);

        $this->deleteJson($this->rowUrl($row))->assertOk();

        $levels = array_column($this->getJson('/api/priority?department_id=1')->json('result.priorities'), 'level');
        $this->assertNotContains(self::MARKER.' Lookup', $levels);
    }
}
