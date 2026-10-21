<?php

namespace Tests\Feature\Admin;

use Database\Factories\DesignationFactory;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Group;

/**
 * Designations are created through POST /designations (covered in
 * DesignationTest), so only list/update/deactivate live here.
 */
#[Group('phase2')]
class AdminDesignationsTest extends AdminResourceTestCase
{
    protected function path(): string
    {
        return '/admin/designations';
    }

    protected function listKey(): string
    {
        return 'designations';
    }

    protected function nameField(): string
    {
        return 'designation';
    }

    protected function table(): string
    {
        return 'designations';
    }

    protected function newRow(string $name, bool $active = true, int $departmentId = 1): Model
    {
        return DesignationFactory::new()->create([
            'designation' => $name,
            'department_id' => $departmentId,
            'status' => $active,
        ]);
    }

    protected function renamePayload(string $name): array
    {
        return ['designation' => $name];
    }

    public function test_rows_carry_the_department_id(): void
    {
        $this->actingAsAdmin();
        $row = $this->newRow(self::MARKER.' Dept', true, 2);

        $found = collect($this->listRows('search='.self::MARKER))->firstWhere('id', $row->id);

        $this->assertSame(['id', 'designation', 'department_id', 'status'], array_keys($found));
        $this->assertSame(2, $found['department_id']);
    }

    public function test_list_can_be_filtered_by_department(): void
    {
        $this->actingAsAdmin();
        $this->newRow(self::MARKER.' IT', true, 1);
        $this->newRow(self::MARKER.' Eng', true, 2);

        $this->assertSame([self::MARKER.' IT'], $this->listNames('search='.self::MARKER.'&department_id=1'));
        $this->assertSame([self::MARKER.' Eng'], $this->listNames('search='.self::MARKER.'&department_id=2'));
    }

    public function test_unknown_department_filter_is_rejected(): void
    {
        $this->actingAsAdmin();

        $this->getJson($this->url('department_id=9999'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['department_id']);
    }

    public function test_designation_can_move_to_another_department(): void
    {
        $this->actingAsAdmin();
        $row = $this->newRow(self::MARKER.' Mover', true, 1);

        $this->patchJson($this->rowUrl($row), ['department_id' => 2])->assertOk();

        $this->assertDatabaseHas('designations', ['id' => $row->id, 'department_id' => 2]);
    }

    public function test_designation_department_must_exist(): void
    {
        $this->actingAsAdmin();
        $row = $this->newRow(self::MARKER.' Mover');

        $this->patchJson($this->rowUrl($row), ['department_id' => 9999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['department_id']);
    }

    public function test_names_are_unique_within_a_department_case_insensitively(): void
    {
        $this->actingAsAdmin();
        $this->newRow('Network Engineer', true, 1);
        $row = $this->newRow('Analyst', true, 1);

        $this->patchJson($this->rowUrl($row), ['designation' => 'NETWORK engineer'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['designation']);
        $this->patchJson($this->rowUrl($row), ['designation' => 'Analyst II'])->assertOk();
        $this->patchJson($this->rowUrl($row), ['designation' => 'ANALYST II', 'status' => true])
            ->assertOk('A row may keep (re-case) its own name');
    }

    public function test_the_same_name_is_allowed_in_another_department(): void
    {
        $this->actingAsAdmin();
        $this->newRow('Supervisor', true, 1);
        $row = $this->newRow('Supervisor', true, 2);

        $this->patchJson($this->rowUrl($row), ['designation' => 'Supervisor'])->assertOk();
    }

    public function test_moving_to_a_department_that_already_has_the_name_is_rejected(): void
    {
        $this->actingAsAdmin();
        $this->newRow('Supervisor', true, 1);
        $row = $this->newRow('supervisor', true, 2);

        $this->patchJson($this->rowUrl($row), ['department_id' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['department_id']);

        $this->assertDatabaseHas('designations', ['id' => $row->id, 'department_id' => 2]);
    }

    public function test_renaming_and_moving_at_once_is_checked_against_the_new_pair(): void
    {
        $this->actingAsAdmin();
        $this->newRow('Lead', true, 1);
        $row = $this->newRow('Other', true, 2);

        $this->patchJson($this->rowUrl($row), ['designation' => 'Lead', 'department_id' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['designation']);
        $this->patchJson($this->rowUrl($row), ['designation' => 'Lead', 'department_id' => 2])->assertOk();
    }

    public function test_deactivated_designations_leave_the_public_list(): void
    {
        $this->actingAsAdmin();
        $row = $this->newRow(self::MARKER.' Gone', true, 1);

        $this->deleteJson($this->rowUrl($row))->assertOk();

        $names = array_column($this->getJson('/api/designations?department_id=1')->json('result.designations'), 'designation');
        $this->assertNotContains(self::MARKER.' Gone', $names);
    }
}
