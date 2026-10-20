<?php

namespace Database\Factories;

use App\Models\NatureOfWork;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NatureOfWork>
 */
class NatureOfWorkFactory extends Factory
{
    protected $model = NatureOfWork::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'label' => fake()->unique()->word(),
            'status' => true,
        ];
    }

    public static function labelled(string $label): NatureOfWork
    {
        return NatureOfWork::query()->where('label', $label)->first()
            ?? static::new()->create(['label' => $label]);
    }
}
