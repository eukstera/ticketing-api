<?php

namespace Tests\Feature\Admin;

use App\Models\Designation;
use App\Models\Role;
use App\Models\User;
use Database\Factories\UserFactory;
use PHPUnit\Framework\Attributes\Group;
use Tests\ApiTestCase;

#[Group('phase2')]
class AdminUsersTest extends ApiTestCase
{
    /**
     * @return list<array<string, mixed>>
     */
    private function listRows(string $query = ''): array
    {
        return $this->getJson('/api/admin/users'.($query === '' ? '' : '?'.$query))
            ->assertOk()
            ->json('result.users.data');
    }

    public function test_list_rows_use_the_user_shape_and_never_expose_the_password(): void
    {
        $this->actingAsAdmin();
        $user = User::factory()->create(['last_name' => 'Qzmarker']);

        $row = collect($this->listRows('search=Qzmarker'))->firstWhere('id', $user->id);

        $this->assertSame(
            ['id', 'first_name', 'last_name', 'email', 'status', 'department_id', 'designation_id', 'role', 'department', 'designation'],
            array_keys($row)
        );
        $this->assertSame(['id', 'name'], array_keys($row['role']));
        $this->assertSame(['id', 'name'], array_keys($row['department']));
        $this->assertSame(['id', 'name'], array_keys($row['designation']));
        $this->assertArrayNotHasKey('password', $row);
    }

    public function test_list_is_a_paginator_with_default_page_size_15(): void
    {
        $this->actingAsAdmin();
        User::factory()->count(20)->create();

        $this->getJson('/api/admin/users')
            ->assertOk()
            ->assertJsonStructure(['result' => ['users' => ['data', 'current_page', 'last_page', 'per_page', 'total']]])
            ->assertJsonPath('result.users.per_page', 15)
            ->assertJsonPath('result.users.total', 21)
            ->assertJsonCount(15, 'result.users.data');
    }

    public function test_per_page_is_bounded(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/admin/users?per_page=100')->assertOk();
        $this->getJson('/api/admin/users?per_page=0')->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
        $this->getJson('/api/admin/users?per_page=101')->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
    }

    public function test_search_matches_first_name_last_name_or_email_case_insensitively(): void
    {
        $this->actingAsAdmin();
        $byFirst = User::factory()->create(['first_name' => 'Zephyrine']);
        $byLast = User::factory()->create(['last_name' => 'Zephyrstone']);
        $byEmail = User::factory()->create(['email' => 'someone@zephyr.example']);
        User::factory()->create(['first_name' => 'Other', 'last_name' => 'Person']);

        $ids = array_column($this->listRows('search=ZEPHYR'), 'id');

        $this->assertEqualsCanonicalizing([$byFirst->id, $byLast->id, $byEmail->id], $ids);
    }

    public function test_status_filter_supports_active_inactive_and_all(): void
    {
        $this->actingAsAdmin();
        $active = User::factory()->create(['last_name' => 'Qzmarker']);
        $inactive = User::factory()->inactive()->create(['last_name' => 'Qzmarker']);

        $this->assertSame([$active->id], array_column($this->listRows('search=Qzmarker&status=active'), 'id'));
        $this->assertSame([$inactive->id], array_column($this->listRows('search=Qzmarker&status=inactive'), 'id'));
        $this->assertEqualsCanonicalizing(
            [$active->id, $inactive->id],
            array_column($this->listRows('search=Qzmarker&status=all'), 'id')
        );
        $this->assertEqualsCanonicalizing(
            [$active->id, $inactive->id],
            array_column($this->listRows('search=Qzmarker'), 'id')
        );
        $this->getJson('/api/admin/users?status=archived')->assertUnprocessable();
    }

    public function test_admin_can_view_a_single_user(): void
    {
        $this->actingAsAdmin();
        $user = User::factory()->create();

        $this->getJson("/api/admin/users/{$user->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('result.user.id', $user->id)
            ->assertJsonPath('result.user.email', $user->email);
    }

    public function test_unknown_user_returns_404(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/admin/users/999999')->assertNotFound()->assertJsonPath('success', false);
        $this->patchJson('/api/admin/users/999999', ['first_name' => 'X'])->assertNotFound();
        $this->deleteJson('/api/admin/users/999999')->assertNotFound();
    }

    public function test_admin_can_update_profile_fields_role_department_and_status(): void
    {
        $this->actingAsAdmin();
        $user = User::factory()->create();
        $headRole = Role::query()->where('role', 'Department Head')->firstOrFail();
        $designation = Designation::query()->where('department_id', 2)->firstOrFail();

        $this->patchJson("/api/admin/users/{$user->id}", [
            'first_name' => 'Renamed',
            'last_name' => 'Person',
            'email' => 'renamed@example.test',
            'department_id' => 2,
            'designation_id' => $designation->id,
            'role_id' => $headRole->id,
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('result.user.first_name', 'Renamed')
            ->assertJsonPath('result.user.department.name', 'Engineering')
            ->assertJsonPath('result.user.role.name', 'Department Head');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => 'renamed@example.test', 'department_id' => 2]);
    }

    public function test_password_is_optional_on_patch_and_hashed_when_given(): void
    {
        $this->actingAsAdmin();
        $user = User::factory()->create();
        $oldHash = $user->password;

        $this->patchJson("/api/admin/users/{$user->id}", ['first_name' => 'Keep'])->assertOk();
        $this->assertSame($oldHash, $user->fresh()->password);

        $this->patchJson("/api/admin/users/{$user->id}", ['password' => 'BrandNew123'])->assertOk();
        $this->assertTrue(password_verify('BrandNew123', $user->fresh()->password));
        $this->assertNotSame('BrandNew123', $user->fresh()->password);
    }

    public function test_patch_validates_email_uniqueness_designation_and_password(): void
    {
        $this->actingAsAdmin();
        $user = User::factory()->create();
        $other = User::factory()->create();
        $itDesignation = Designation::query()->where('department_id', 1)->firstOrFail();

        $this->patchJson("/api/admin/users/{$user->id}", ['email' => $other->email])
            ->assertUnprocessable()->assertJsonValidationErrors(['email']);
        $this->patchJson("/api/admin/users/{$user->id}", ['email' => $user->email])->assertOk();
        $this->patchJson("/api/admin/users/{$user->id}", ['department_id' => 2, 'designation_id' => $itDesignation->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['designation_id']);
        $this->patchJson("/api/admin/users/{$user->id}", ['password' => 'short'])
            ->assertUnprocessable()->assertJsonValidationErrors(['password']);
        $this->patchJson("/api/admin/users/{$user->id}", ['role_id' => 9999])
            ->assertUnprocessable()->assertJsonValidationErrors(['role_id']);
    }

    public function test_delete_deactivates_the_user_and_revokes_tokens(): void
    {
        $this->actingAsAdmin();
        $user = User::factory()->create();
        $user->createToken('one');
        $user->createToken('two');

        $this->deleteJson("/api/admin/users/{$user->id}")->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'status' => 0]);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_deactivated_user_cannot_log_in_until_reactivated(): void
    {
        $this->actingAsAdmin();
        $user = User::factory()->create(['email' => 'cycle@example.test']);
        $credentials = ['email' => 'cycle@example.test', 'password' => UserFactory::PASSWORD];

        $this->deleteJson("/api/admin/users/{$user->id}")->assertOk();
        $this->postJson('/api/login', $credentials)->assertForbidden();

        $this->patchJson("/api/admin/users/{$user->id}", ['status' => true])
            ->assertOk()
            ->assertJsonPath('result.user.status', true);
        $this->postJson('/api/login', $credentials)->assertSuccessful();
    }

    public function test_patching_status_false_deactivates_and_revokes_tokens(): void
    {
        $this->actingAsAdmin();
        $user = User::factory()->create();
        $user->createToken('one');

        $this->patchJson("/api/admin/users/{$user->id}", ['status' => false])->assertOk();

        $this->assertSame(0, $user->tokens()->count());
        $this->assertFalse((bool) $user->fresh()->status);
    }

    public function test_non_admins_are_forbidden_on_every_user_route(): void
    {
        $this->actingAsUser();
        $user = User::factory()->create();

        $this->getJson("/api/admin/users/{$user->id}")->assertForbidden();
        $this->patchJson("/api/admin/users/{$user->id}", ['first_name' => 'X'])->assertForbidden();
        $this->deleteJson("/api/admin/users/{$user->id}")->assertForbidden();
        $this->assertSame($user->first_name, $user->fresh()->first_name);
    }

    public function test_anonymous_requests_are_unauthorized(): void
    {
        $user = User::factory()->create();

        $this->getJson("/api/admin/users/{$user->id}")->assertUnauthorized();
        $this->patchJson("/api/admin/users/{$user->id}", [])->assertUnauthorized();
        $this->deleteJson("/api/admin/users/{$user->id}")->assertUnauthorized();
    }
}
