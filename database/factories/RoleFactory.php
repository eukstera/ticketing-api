<?php

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    public const NORMAL_USER = 'Normal User';

    public const DEPARTMENT_HEAD = 'Department Head';

    public const ENCODER = 'Encoder';

    protected $model = Role::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'role' => fake()->unique()->jobTitle(),
            'status' => true,
        ];
    }

    public function normalUser(): static
    {
        return $this->state(['role' => self::NORMAL_USER]);
    }

    public function departmentHead(): static
    {
        return $this->state(['role' => self::DEPARTMENT_HEAD]);
    }

    public function encoder(): static
    {
        return $this->state(['role' => self::ENCODER]);
    }

    public static function named(string $name): Role
    {
        return Role::query()->where('role', $name)->first()
            ?? static::new()->create(['role' => $name]);
    }
}
