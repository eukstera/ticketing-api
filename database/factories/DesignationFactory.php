<?php

namespace Database\Factories;

use App\Models\Designation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Designation>
 */
class DesignationFactory extends Factory
{
    protected $model = Designation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'department_id' => DepartmentFactory::new(),
            'designation' => fake()->unique()->jobTitle(),
            'status' => true,
        ];
    }

    public static function forDepartmentId(int $departmentId): Designation
    {
        return Designation::query()->where('department_id', $departmentId)->orderBy('id')->first()
            ?? static::new()->create(['department_id' => $departmentId]);
    }
}
