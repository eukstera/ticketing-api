<?php

namespace Database\Factories;

use App\Models\ReportSignatory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportSignatory>
 */
class ReportSignatoryFactory extends Factory
{
    protected $model = ReportSignatory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => strtoupper(fake()->unique()->name()),
            'title' => fake()->jobTitle(),
            'is_default' => false,
            'status' => true,
        ];
    }

    public function default(): static
    {
        return $this->state(['is_default' => true]);
    }

    public function inactive(): static
    {
        return $this->state(['status' => false]);
    }
}
