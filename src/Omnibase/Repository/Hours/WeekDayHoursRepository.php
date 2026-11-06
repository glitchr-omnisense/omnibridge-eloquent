<?php

namespace Omnibridge\Eloquent\Omnibase\Repository\Hours;

use Base\Entity\Hours\WeekDayHours;
use Base\Repository\Hours\WeekDays;
use Omnibridge\Eloquent\Omnibase\EloquentEntities;

/**
 * omnibridge/doctrine's Hours\WeekDayHoursRepository on Eloquent (plan "omnibase agnostic", step F7b): the same finders, compiled by
 * EloquentFinder, the writes through the unit of work (step F7c).
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

    public function save(array $week): void
    {
        // By weekday, the entity's key, as omnibridge/doctrine's.
        $days = [];
        foreach ($this->findAll() as $day) {
            $days[$day->getWeekday()] = $day;
        }
        for ($n = 1; $n <= 7; ++$n) {
            $day = $days[$n] ?? new WeekDayHours($n);
            $day->setHours($week[$n] ?? []);
            $this->unitOfWork()->persist($day);
        }
        $this->unitOfWork()->flush();
    }
}
