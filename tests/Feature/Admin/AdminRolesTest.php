<?php

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\User;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Group;

#[Group('phase2')]
class AdminRolesTest extends AdminResourceTestCase
{
    use ChecksAdminCreate;

    protected function path(): string
    {
        return '/admin/roles';
    }

    protected function listKey(): string
    {
        return 'roles';
    }

    protected function nameField(): string
    {
        return 'name';
    }

    protected function table(): string
    {
        return 'roles';
    }

    protected function newRow(string $name, bool $active = true): Model
    {
        return RoleFactory::new()->create(['role' => $name, 'status' => $active]);
    }

    protected function renamePayload(string $name): array
    {
        return ['name' => $name];
    }

    protected function createPayload(string $name): array
    {
        return ['name' => $name];
    }

    private function departmentHeadRole(): Role
    {
        return Role::query()->where('role', 'Department Head')->firstOrFail();
    }

    public function test_department_head_role_cannot_be_renamed(): void
    {
        $this->actingAsAdmin();
        $role = $this->departmentHeadRole();

        $this->patchJson($this->rowUrl($role), ['name' => 'Supervisor'])
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertSame('Department Head', $role->fresh()->role);
    }

    public function test_department_head_role_cannot_be_deleted_or_deactivated(): void
    {
        $this->actingAsAdmin();
        $role = $this->departmentHeadRole();

        $this->deleteJson($this->rowUrl($role))->assertStatus(409)->assertJsonPath('success', false);
        $this->patchJson($this->rowUrl($role), ['status' => false])->assertStatus(409);

        $this->assertTrue((bool) $role->fresh()->status);
    }

    public function test_department_head_role_can_be_patched_with_unchanged_values(): void
    {
        $this->actingAsAdmin();
        $role = $this->departmentHeadRole();

        $this->patchJson($this->rowUrl($role), ['name' => 'Department Head', 'status' => true])->assertOk();
    }

    public function test_role_held_by_active_users_cannot_be_deactivated(): void
    {
        $this->actingAsAdmin();
        $role = $this->newRow(self::MARKER.' Busy');
        User::factory()->count(2)->create(['role_id' => $role->getKey()]);

        $this->deleteJson($this->rowUrl($role))
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.role.0', '2 active users hold this role');
        $this->patchJson($this->rowUrl($role), ['status' => false])
            ->assertStatus(409)
            ->assertJsonPath('errors.role.0', '2 active users hold this role');

        $this->assertTrue((bool) $role->fresh()->status);
    }

    public function test_only_active_users_count_towards_the_deactivation_block(): void
    {
        $this->actingAsAdmin();
        $role = $this->newRow(self::MARKER.' Quiet');
        User::factory()->inactive()->create(['role_id' => $role->getKey()]);
        User::factory()->create(['role_id' => $role->getKey()]);

        $this->deleteJson($this->rowUrl($role))
            ->assertStatus(409)
            ->assertJsonPath('errors.role.0', '1 active users hold this role');
    }

    public function test_role_held_only_by_inactive_users_can_be_deactivated(): void
    {
        $this->actingAsAdmin();
        $role = $this->newRow(self::MARKER.' Empty');
        User::factory()->inactive()->create(['role_id' => $role->getKey()]);

        $this->deleteJson($this->rowUrl($role))->assertOk();

        $this->assertFalse((bool) $role->fresh()->status);
    }

    public function test_public_roles_lookup_lists_active_roles_with_status(): void
    {
        $this->actingAsUser();
        $this->newRow(self::MARKER.' Hidden', false);

        $roles = $this->getJson('/api/roles')->assertOk()->json('result.roles');

        $this->assertNotContains(self::MARKER.' Hidden', array_column($roles, 'name'));
        $this->assertEqualsCanonicalizing(['Normal User', 'Department Head', 'Encoder'], array_column($roles, 'name'));
        $this->assertSame(['id', 'name', 'status'], array_keys($roles[0]));
        $this->assertTrue($roles[0]['status']);
    }
}
