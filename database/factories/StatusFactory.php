<?php

namespace Database\Factories;

use App\Models\Status;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Status>
 */
class StatusFactory extends Factory
{
    public const OPEN = 'Open';

    public const IN_PROGRESS = 'In-progress';

    public const COMPLETED = 'Completed';

    public const OVERDUE = 'Overdue';

    public const FALSE_ALARM = 'False Alarm';

    public const PENDING_CONFIRMATION = 'Pending Confirmation';

    protected $model = Status::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'label' => fake()->randomElement([self::OPEN, self::IN_PROGRESS, self::COMPLETED, self::OVERDUE]),
            'status' => true,
        ];
    }

    public static function labelled(string $label): Status
    {
        return Status::query()->where('label', $label)->first()
            ?? static::new()->create(['label' => $label]);
    }
}
