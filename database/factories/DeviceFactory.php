<?php

namespace Database\Factories;

use App\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    protected $model = Device::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ticket_id' => TicketFactory::new()->withoutDetails(),
            'device' => fake()->randomElement(['Monitor', 'Printer', 'Keyboard', 'Mouse', 'CPU', 'Laptop']),
            'qty' => fake()->numberBetween(1, 5),
            'status' => true,
        ];
    }
}
