<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Group;
use Tests\ApiTestCase;

#[Group('phase2')]
class ProfileTest extends ApiTestCase
{
    private function passwordPayload(array $overrides = []): array
    {
        return array_merge([
            'current_password' => UserFactory::PASSWORD,
            'password' => 'NewPassw0rd',
            'password_confirmation' => 'NewPassw0rd',
        ], $overrides);
    }

    public function test_user_can_update_their_names(): void
    {
        $user = $this->actingAsUser();

        $this->patchJson('/api/me', ['first_name' => 'Maria', 'last_name' => 'Santos'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('result.user.id', $user->id)
            ->assertJsonPath('result.user.first_name', 'Maria')
            ->assertJsonPath('result.user.last_name', 'Santos')
            ->assertJsonStructure(['result' => ['user' => ['role' => ['id', 'name'], 'department' => ['id', 'name']]]]);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'first_name' => 'Maria', 'last_name' => 'Santos']);
    }

    public function test_either_name_can_be_sent_alone(): void
    {
        $user = $this->actingAsUser();
        $lastName = $user->last_name;

        $this->patchJson('/api/me', ['first_name' => 'Solo'])->assertOk();

        $this->assertSame('Solo', $user->fresh()->first_name);
        $this->assertSame($lastName, $user->fresh()->last_name);
    }

    public function test_at_least_one_name_is_required(): void
    {
        $this->actingAsUser();

        $this->patchJson('/api/me', [])
            ->assertUnprocessable()
            ->assertJsonPath('success', false);
    }

    public function test_names_are_validated(): void
    {
        $this->actingAsUser();

        $this->patchJson('/api/me', ['first_name' => str_repeat('a', 101)])
            ->assertUnprocessable()->assertJsonValidationErrors(['first_name']);
        $this->patchJson('/api/me', ['last_name' => str_repeat('a', 101)])
            ->assertUnprocessable()->assertJsonValidationErrors(['last_name']);
        $this->patchJson('/api/me', ['first_name' => str_repeat('a', 100)])->assertOk();
    }

    public function test_protected_fields_are_ignored(): void
    {
        $user = $this->actingAsUser();
        $original = $user->only(['role_id', 'department_id', 'designation_id', 'status', 'email']);

        $this->patchJson('/api/me', [
            'first_name' => 'Changed',
            'role_id' => 2,
            'department_id' => 2,
            'designation_id' => 2,
            'status' => false,
            'email' => 'hijack@example.test',
        ])->assertOk();

        $fresh = $user->fresh();
        $this->assertSame('Changed', $fresh->first_name);
        $this->assertEquals($original, $fresh->only(['role_id', 'department_id', 'designation_id', 'status', 'email']));
    }

    public function test_profile_update_requires_authentication(): void
    {
        $this->patchJson('/api/me', ['first_name' => 'X'])->assertUnauthorized();
        $this->patchJson('/api/me/password', $this->passwordPayload())->assertUnauthorized();
    }

    public function test_profile_update_is_throttled_to_ten_per_minute(): void
    {
        $this->actingAsUser();

        foreach (range(1, 10) as $i) {
            $this->patchJson('/api/me', ['first_name' => "Name{$i}"])->assertOk();
        }

        $this->patchJson('/api/me', ['first_name' => 'Eleventh'])->assertTooManyRequests();
    }

    public function test_password_can_be_changed(): void
    {
        $user = $this->actingAsUser();

        $this->patchJson('/api/me/password', $this->passwordPayload())
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue(Hash::check('NewPassw0rd', $user->fresh()->password));
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'NewPassw0rd'])->assertSuccessful();
    }

    public function test_wrong_current_password_is_rejected(): void
    {
        $user = $this->actingAsUser();

        $this->patchJson('/api/me/password', $this->passwordPayload(['current_password' => 'not-my-password']))
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['current_password']);

        $this->assertTrue(Hash::check(UserFactory::PASSWORD, $user->fresh()->password));
    }

    public function test_new_password_rules_are_enforced(): void
    {
        $this->actingAsUser();

        $weak = [
            'short' => 'Ab1',
            'no uppercase' => 'newpassw0rd',
            'no lowercase' => 'NEWPASSW0RD',
            'no number' => 'NewPassword',
        ];

        foreach ($weak as $label => $password) {
            $this->patchJson('/api/me/password', $this->passwordPayload([
                'password' => $password,
                'password_confirmation' => $password,
            ]))->assertUnprocessable($label)->assertJsonValidationErrors(['password']);
        }
    }

    public function test_confirmation_must_match(): void
    {
        $this->actingAsUser();

        $this->patchJson('/api/me/password', $this->passwordPayload(['password_confirmation' => 'Different1']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    public function test_new_password_must_differ_from_the_current_one(): void
    {
        $this->actingAsUser();

        $this->patchJson('/api/me/password', $this->passwordPayload([
            'password' => UserFactory::PASSWORD,
            'password_confirmation' => UserFactory::PASSWORD,
        ]))->assertUnprocessable()->assertJsonValidationErrors(['password']);
    }

    public function test_all_fields_are_required(): void
    {
        $this->actingAsUser();

        $this->patchJson('/api/me/password', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password', 'password']);
    }

    public function test_other_tokens_are_revoked_and_the_current_one_is_kept(): void
    {
        $user = User::factory()->create();
        $current = $user->createToken('current')->plainTextToken;
        $user->createToken('phone');
        $user->createToken('laptop');

        $this->withToken($current)->patchJson('/api/me/password', $this->passwordPayload())->assertOk();

        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame('current', $user->tokens()->first()->name);

        $this->app['auth']->forgetGuards();
        $this->withToken($current)->getJson('/api/me')->assertOk();
    }

    public function test_failed_password_change_keeps_every_token(): void
    {
        $user = User::factory()->create();
        $current = $user->createToken('current')->plainTextToken;
        $user->createToken('phone');

        $this->withToken($current)
            ->patchJson('/api/me/password', $this->passwordPayload(['current_password' => 'wrong']))
            ->assertUnprocessable();

        $this->assertSame(2, $user->tokens()->count());
    }

    public function test_password_change_is_throttled_to_ten_per_minute(): void
    {
        $this->actingAsUser();

        foreach (range(1, 10) as $i) {
            $this->patchJson('/api/me/password', $this->passwordPayload(['current_password' => 'wrong']))
                ->assertUnprocessable();
        }

        $this->patchJson('/api/me/password', $this->passwordPayload())->assertTooManyRequests();
    }
}
