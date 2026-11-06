<?php

namespace Omnibridge\Eloquent\Omnibase\Repository\Hours;

use Base\Entity\Hours\ScopedWeek;
use Base\Repository\Hours\ScopedWeeks;
use Omnibridge\Eloquent\Omnibase\EloquentEntities;

/**
 * omnibridge/doctrine's Hours\ScopedWeekRepository on Eloquent (plan "omnibase agnostic", step F7b): the same finders, compiled by
 * EloquentFinder, the writes through the unit of work (step F7c).
 *
 * @extends EloquentEntities<ScopedWeek>
 */
class ScopedWeekRepository extends EloquentEntities implements ScopedWeeks
{
    public function ofScope(string $scope): ?ScopedWeek
    {
        return $this->findOneBy(['scope' => $scope]);
    }

    public function week(string $scope): ?array
    {
        return $this->ofScope($scope)?->getWeek();
    }

    public function save(string $scope, array $week): ScopedWeek
    {
        $row = $this->ofScope($scope);
        $row ? $row->setWeek($week) : $row = new ScopedWeek($scope, $week);
        $this->unitOfWork()->persist($row);
        $this->unitOfWork()->flush();

        return $row;
    }

    public function forget(string $scope): void
    {
        if ($row = $this->ofScope($scope)) {
            $this->unitOfWork()->remove($row);
            $this->unitOfWork()->flush();
        }
    }
}
