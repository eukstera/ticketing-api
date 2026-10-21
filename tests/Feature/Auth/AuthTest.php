<?php

namespace Tests\Feature\Auth;

use App\Models\Designation;
use App\Models\Role;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Group;
use Tests\ApiTestCase;

class AuthTest extends ApiTestCase
{
    public function test_login_returns_user_token_and_seven_day_expiry(): void
    {
        $user = User::factory()->departmentHead()->create(['email' => 'head@example.test']);

        $response = $this->postJson('/api/login', [
            'email' => 'head@example.test',
            'password' => UserFactory::PASSWORD,
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('success', true)
            ->assertJsonPath('result.user.id', $user->id)
            ->assertJsonPath('result.user.role.name', 'Department Head')
            ->assertJsonPath('result.user.status', true)
            ->assertJsonStructure(['result' => [
                'user' => ['id', 'first_name', 'last_name', 'email', 'status', 'department_id', 'designation_id',
                    'role' => ['id', 'name'], 'department' => ['id', 'name'], 'designation' => ['id', 'name']],
                'token',
                'expires_at',
            ]]);

        $expiresAt = Carbon::parse($response->json('result.expires_at'));
        $this->assertTrue($expiresAt->between(now()->addDays(7)->subMinutes(2), now()->addDays(7)->addMinutes(2)));
        $this->assertArrayNotHasKey('password', $response->json('result.user'));
    }

    public function test_login_with_wrong_password_is_unauthorized(): void
    {
        User::factory()->create(['email' => 'user@example.test']);

        $this->postJson('/api/login', ['email' => 'user@example.test', 'password' => 'wrong-password'])
            ->assertUnauthorized()
            ->assertJsonPath('success', false);
    }

    public function test_login_validates_input(): void
    {
        $this->postJson('/api/login', ['email' => 'not-an-email'])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_deactivated_user_cannot_log_in(): void
    {
        User::factory()->inactive()->create(['email' => 'gone@example.test']);

        $this->postJson('/api/login', ['email' => 'gone@example.test', 'password' => UserFactory::PASSWORD])
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_login_is_throttled_after_five_attempts_per_minute(): void
    {
        User::factory()->create(['email' => 'user@example.test']);

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/login', ['email' => 'user@example.test', 'password' => 'wrong'])
                ->assertUnauthorized();
        }

        $this->postJson('/api/login', ['email' => 'user@example.test', 'password' => 'wrong'])
            ->assertTooManyRequests();
    }

    public function test_throttle_is_keyed_by_email(): void
    {
        User::factory()->create(['email' => 'one@example.test']);
        User::factory()->create(['email' => 'two@example.test']);

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/login', ['email' => 'one@example.test', 'password' => 'wrong']);
        }

        $this->postJson('/api/login', ['email' => 'two@example.test', 'password' => UserFactory::PASSWORD])
            ->assertSuccessful();
    }

    public function test_me_returns_the_authenticated_user(): void
    {
        $user = $this->actingAsUser($this->itUser());

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('result.user.id', $user->id)
            ->assertJsonPath('result.user.role.name', 'Normal User')
            ->assertJsonPath('result.user.department.name', 'IT');
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/me')
            ->assertUnauthorized()
            ->assertJsonPath('success', false);
    }

    public function test_logout_revokes_the_current_token_only(): void
    {
        $user = User::factory()->create();
        $current = $user->createToken('current')->plainTextToken;
        $user->createToken('other');

        $this->withToken($current)->postJson('/api/logout')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'other']);
    }

    public function test_revoked_token_can_no_longer_be_used(): void
    {
        $token = User::factory()->create()->createToken('api')->plainTextToken;

        $this->withToken($token)->getJson('/api/me')->assertOk();
        $this->withToken($token)->postJson('/api/logout')->assertOk();

        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_token_expires_after_seven_days(): void
    {
        $token = User::factory()->create()->createToken('api')->plainTextToken;

        $this->withToken($token)->getJson('/api/me')->assertOk();

        $this->travel(6)->days();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/me')->assertOk();

        $this->travel(2)->days();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/me')
            ->assertUnauthorized()
            ->assertJsonPath('success', false);
    }

    public function test_public_registration_is_removed(): void
    {
        $this->postJson('/api/register', [
            'first_name' => 'Eve',
            'last_name' => 'Intruder',
            'email' => 'eve@example.test',
            'password' => 'password123',
            'department_id' => 1,
            'designation_id' => 1,
            'role_id' => 2,
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'eve@example.test']);
    }

    public function test_admin_can_create_a_user(): void
    {
        $this->actingAsAdmin();
        $designation = Designation::query()->where('department_id', 2)->firstOrFail();
        $roleId = Role::query()->where('role', 'Normal User')->value('id');

        $response = $this->postJson('/api/admin/users', [
            'first_name' => 'New',
            'last_name' => 'Hire',
            'email' => 'new.hire@example.test',
            'password' => 'password123',
            'department_id' => 2,
            'designation_id' => $designation->id,
            'role_id' => $roleId,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('result.user.email', 'new.hire@example.test')
            ->assertJsonPath('result.user.role.name', 'Normal User')
            ->assertJsonPath('result.user.department.name', 'Engineering');

        $this->assertTrue(
            password_verify('password123', User::where('email', 'new.hire@example.test')->value('password'))
        );
    }

    public function test_admin_user_creation_is_validated(): void
    {
        $this->actingAsAdmin();
        $itDesignation = Designation::query()->where('department_id', 1)->firstOrFail();

        $this->postJson('/api/admin/users', [
            'first_name' => 'Bad',
            'last_name' => 'Input',
            'email' => 'bad@example.test',
            'password' => 'short',
            'department_id' => 2,
            'designation_id' => $itDesignation->id,
            'role_id' => 1,
        ])->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['password', 'designation_id']);
    }

    public function test_admin_user_creation_requires_a_unique_email(): void
    {
        $admin = $this->actingAsAdmin();

        $this->postJson('/api/admin/users', [
            'first_name' => 'Dup',
            'last_name' => 'Email',
            'email' => $admin->email,
            'password' => 'password123',
            'department_id' => $admin->department_id,
            'designation_id' => $admin->designation_id,
            'role_id' => $admin->role_id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['email']);
    }

    public function test_non_admin_cannot_use_admin_user_routes(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/admin/users', [])
            ->assertForbidden()
            ->assertJsonPath('success', false);
        $this->getJson('/api/admin/users')->assertForbidden();
    }

    public function test_admin_user_routes_require_authentication(): void
    {
        $this->postJson('/api/admin/users', [])->assertUnauthorized();
        $this->getJson('/api/admin/users')->assertUnauthorized();
    }

    #[Group('phase2')]
    public function test_admin_can_list_users_with_pagination(): void
    {
        $this->actingAsAdmin();
        User::factory()->count(3)->create();

        $this->getJson('/api/admin/users?per_page=2')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'result.users.data')
            ->assertJsonPath('result.users.total', 4);
    }

    #[Group('phase2')]
    public function test_admin_can_deactivate_a_user_and_revoke_their_tokens(): void
    {
        $this->actingAsAdmin();
        $target = User::factory()->create();
        $target->createToken('api');

        $this->deleteJson("/api/admin/users/{$target->id}")->assertOk();

        $this->assertFalse((bool) $target->fresh()->status);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
