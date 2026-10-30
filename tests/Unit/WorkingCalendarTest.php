<?php

namespace Tests\Unit;

use App\Services\WorkingCalendar;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class WorkingCalendarTest extends TestCase
{
    private function due(string $start, int $days, ?WorkingCalendar $calendar = null): string
    {
        return ($calendar ?? new WorkingCalendar)->dueAt(Carbon::parse($start), $days)->format('Y-m-d H:i');
    }

    public function test_a_weekday_request_is_due_at_the_start_of_the_day_n_working_days_later(): void
    {
        // Monday 18 May 2026
        $this->assertSame('2026-05-19 00:00', $this->due('2026-05-18 15:30', 1));
        $this->assertSame('2026-05-21 00:00', $this->due('2026-05-18 09:00', 3));
        $this->assertSame('2026-05-25 00:00', $this->due('2026-05-18 09:00', 5));
    }

    public function test_weekends_are_skipped(): void
    {
        // Friday 15 May + 1 working day is Monday 18 May
        $this->assertSame('2026-05-18 00:00', $this->due('2026-05-15 16:00', 1));
        $this->assertSame('2026-05-19 00:00', $this->due('2026-05-15 16:00', 2));
        $this->assertSame('2026-06-05 00:00', $this->due('2026-05-15 16:00', 15));
    }

    public function test_a_weekend_request_starts_counting_from_the_next_monday(): void
    {
        // Saturday 16 and Sunday 17 May both count from Monday 18 May
        $this->assertSame('2026-05-19 00:00', $this->due('2026-05-16 10:00', 1));
        $this->assertSame('2026-05-19 00:00', $this->due('2026-05-17 23:59', 1));
        $this->assertSame('2026-05-21 00:00', $this->due('2026-05-16 10:00', 3));
    }

    public function test_zero_or_negative_days_leave_the_start_day(): void
    {
        $this->assertSame('2026-05-18 00:00', $this->due('2026-05-18 15:30', 0));
        $this->assertSame('2026-05-18 00:00', $this->due('2026-05-16 15:30', -2));
    }

    public function test_a_holiday_list_can_be_added_by_overriding_one_method(): void
    {
        $calendar = new class extends WorkingCalendar
        {
            protected function isHoliday(CarbonInterface $day): bool
            {
                return $day->toDateString() === '2026-05-19';
            }
        };

        // Tuesday 19 May is a holiday: Monday + 1 working day is Wednesday
        $this->assertSame('2026-05-20 00:00', $this->due('2026-05-18 09:00', 1, $calendar));
        $this->assertSame('2026-05-19 00:00', $this->due('2026-05-18 09:00', 1));
    }
}
