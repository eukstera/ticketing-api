<?php

namespace Database\Factories;

use App\Models\TypeOfConcern;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TypeOfConcern>
 */
class TypeOfConcernFactory extends Factory
{
    protected $model = TypeOfConcern::class;

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

    public static function labelled(string $label): TypeOfConcern
    {
        return TypeOfConcern::query()->where('label', $label)->first()
            ?? static::new()->create(['label' => $label]);
    }
}
