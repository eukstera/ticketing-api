<?php

namespace Database\Factories;

use App\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    protected $model = Department::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'department' => fake()->unique()->company(),
            'status' => true,
        ];
    }

    public static function named(string $name): Department
    {
        return Department::query()->where('department', $name)->first()
            ?? static::new()->create(['department' => $name]);
    }
}
