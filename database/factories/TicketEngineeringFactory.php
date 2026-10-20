<?php

namespace Database\Factories;

use App\Models\TicketEngineering;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketEngineering>
 */
class TicketEngineeringFactory extends Factory
{
    protected $model = TicketEngineering::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ticket_id' => TicketFactory::new()->withoutDetails(),
            'nature_of_work' => NatureOfWorkFactory::new(),
            'scope_of_work' => fake()->sentence(3),
            'other_nature_of_work' => null,
            'name_of_area' => fake()->randomElement(['Emergency Room', 'OPD', 'Pharmacy', 'Laboratory', 'ICU']),
            'area_supervisor' => fake()->name(),
            'status' => true,
        ];
    }
}
