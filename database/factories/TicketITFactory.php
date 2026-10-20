<?php

namespace Database\Factories;

use App\Models\TicketIT;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketIT>
 */
class TicketITFactory extends Factory
{
    protected $model = TicketIT::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ticket_id' => TicketFactory::new()->withoutDetails(),
            'type_of_concern' => TypeOfConcernFactory::new(),
            'name_of_area' => fake()->randomElement(['Emergency Room', 'OPD', 'Pharmacy', 'Laboratory', 'ICU']),
            'remarks' => fake()->sentence(8),
            'area_supervisor' => fake()->name(),
            'status' => true,
        ];
    }
}
