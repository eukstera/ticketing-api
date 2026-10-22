<?php

namespace Database\Factories;

use App\Models\DateCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DateCategory>
 */
class DateCategoryFactory extends Factory
{
    public const REQUESTED = 'Requested Date';

    public const ASSIGNED = 'Assigned Date';

    public const COMPLETED = 'Completed Date';

    public const FALSE_ALARM = 'False Alarm Date';

    public const REOPENED = 'Reopened Date';

    public const CONFIRMED = 'Confirmed Date';

    public const DISPUTED = 'Disputed Date';

    public const AUTO_CLOSED = 'Auto-closed Date';

    protected $model = DateCategory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category' => self::REQUESTED,
            'status' => true,
        ];
    }

    public static function named(string $category): DateCategory
    {
        return DateCategory::query()->where('category', $category)->first()
            ?? static::new()->create(['category' => $category]);
    }
}
