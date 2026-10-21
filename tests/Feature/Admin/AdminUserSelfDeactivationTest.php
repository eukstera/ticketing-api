<?php

namespace Tests\Feature\Admin;

use PHPUnit\Framework\Attributes\Group;
use Tests\ApiTestCase;

#[Group('phase2')]
class AdminUserSelfDeactivationTest extends ApiTestCase
{
    public function test_admin_cannot_deactivate_their_own_account_with_delete(): void
    {
        $admin = $this->actingAsAdmin();

        $this->deleteJson("/api/admin/users/{$admin->id}")
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertTrue((bool) $admin->fresh()->status);
    }

    public function test_admin_cannot_deactivate_their_own_account_with_patch(): void
    {
        $admin = $this->actingAsAdmin();

        $this->patchJson("/api/admin/users/{$admin->id}", ['status' => false])
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertTrue((bool) $admin->fresh()->status);
    }

    public function test_admin_can_still_update_their_own_profile_fields(): void
    {
        $admin = $this->actingAsAdmin();

        $this->patchJson("/api/admin/users/{$admin->id}", ['first_name' => 'Renamed', 'status' => true])
            ->assertOk()
            ->assertJsonPath('result.user.first_name', 'Renamed');
    }
}
