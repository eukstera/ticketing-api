<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Counts SLA days as working days: Monday to Friday. A holiday list can be added
 * later by overriding (or filling in) isHoliday(); nothing else needs to change.
 *
 * A ticket is due at the start of the day that lies N working days after the day
 * its clock started, so it has to be finished before that day begins. A clock
 * that starts on a Saturday or Sunday starts counting from the next working day.
 */
class WorkingCalendar
{
    public function isWorkingDay(CarbonInterface $day): bool
    {
        return $day->dayOfWeekIso <= 5 && ! $this->isHoliday($day);
    }

    /**
     * Placeholder for a future holiday list.
     */
    protected function isHoliday(CarbonInterface $day): bool
    {
        return false;
    }

    /**
     * The day counting starts on: the clock's own day, or the next working day when it falls on a day off.
     */
    public function countingStartsOn(CarbonInterface $clockStart): Carbon
    {
        $day = Carbon::instance($clockStart)->startOfDay();

        while (! $this->isWorkingDay($day)) {
            $day->addDay();
        }

        return $day;
    }

    /**
     * The moment a ticket whose clock started at $clockStart is due, for an SLA of $workingDays.
     */
    public function dueAt(CarbonInterface $clockStart, int $workingDays): Carbon
    {
        $day = $this->countingStartsOn($clockStart);
        $remaining = max($workingDays, 0);

        while ($remaining > 0) {
            $day->addDay();

            if ($this->isWorkingDay($day)) {
                $remaining--;
            }
        }

        return $day;
    }
}
