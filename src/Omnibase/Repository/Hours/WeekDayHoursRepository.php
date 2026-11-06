<?php

namespace Omnibridge\Eloquent\Omnibase\Repository\Hours;

use Base\Entity\Hours\WeekDayHours;
use Base\Repository\Hours\WeekDays;
use Omnibridge\Eloquent\Omnibase\EloquentEntities;
use Omnibridge\Eloquent\UnsupportedPersistence;

/**
 * omnibridge/doctrine's Hours\WeekDayHoursRepository on Eloquent (plan "omnibase agnostic", step F7b): the same finders, compiled by
 * EloquentFinder.
 *
 * @extends EloquentEntities<WeekDayHours>
 */
class WeekDayHoursRepository extends EloquentEntities implements WeekDays
{
    public function week(): array
    {
        $week = [];
        foreach ($this->findAll() as $day) {
            $week[$day->getWeekday()] = $day->getHours();
        }
        ksort($week);

        return $week;
    }

    /** Writes: refused until step F7c. */
    public function save(array $week): void
    {
        throw UnsupportedPersistence::writes('save');
    }
}
