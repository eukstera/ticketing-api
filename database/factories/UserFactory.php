<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * Users default to the "Normal User" role in an existing (or new) department,
 * with a designation that belongs to that same department. Password is
 * "Secret@45".
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    public const PASSWORD = 'Secret@45';

    protected $model = User::class;

    protected static ?string $hashedPassword = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'department_id' => fn () => Department::query()->orderBy('id')->value('id') ?? DepartmentFactory::new()->create()->id,
            'designation_id' => fn (array $attributes) => DesignationFactory::forDepartmentId($attributes['department_id'])->id,
            'role_id' => fn () => RoleFactory::named(RoleFactory::NORMAL_USER)->id,
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$hashedPassword ??= Hash::make(self::PASSWORD),
            'status' => true,
        ];
    }

    public function normalUser(): static
    {
        return $this->state(fn () => ['role_id' => RoleFactory::named(RoleFactory::NORMAL_USER)->id]);
    }

    public function departmentHead(): static
    {
        return $this->state(fn () => ['role_id' => RoleFactory::named(RoleFactory::DEPARTMENT_HEAD)->id]);
    }

    public function inDepartment(Department|int $department): static
    {
        $departmentId = $department instanceof Department ? $department->id : $department;

        return $this->state(fn () => ['department_id' => $departmentId]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => false]);
    }
}
