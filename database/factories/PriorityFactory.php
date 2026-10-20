<?php

namespace Database\Factories;

use App\Models\Priority;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Priority>
 */
class PriorityFactory extends Factory
{
    protected $model = Priority::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $days = fake()->numberBetween(1, 10);

        return [
            'department_id' => DepartmentFactory::new(),
            'level' => fake()->unique()->words(2, true),
            'SLA' => (string) $days,
            'sla_days' => $days,
            'status' => true,
        ];
    }

    public function slaDays(int $days): static
    {
        return $this->state(['SLA' => (string) $days, 'sla_days' => $days]);
    }

    public static function forDepartmentId(int $departmentId): Priority
    {
        return Priority::query()->where('department_id', $departmentId)->orderBy('id')->first()
            ?? static::new()->create(['department_id' => $departmentId]);
    }
}
