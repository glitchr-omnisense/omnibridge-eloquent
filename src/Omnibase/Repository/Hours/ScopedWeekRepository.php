<?php

namespace Omnibridge\Eloquent\Omnibase\Repository\Hours;

use Base\Entity\Hours\ScopedWeek;
use Base\Repository\Hours\ScopedWeeks;
use Omnibridge\Eloquent\Omnibase\EloquentEntities;
use Omnibridge\Eloquent\UnsupportedPersistence;

/**
 * omnibridge/doctrine's Hours\ScopedWeekRepository on Eloquent (plan "omnibase agnostic", step F7b): the same finders, compiled by
 * EloquentFinder.
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

    /** Writes: refused until step F7c. */
    public function save(string $scope, array $week): ScopedWeek
    {
        throw UnsupportedPersistence::writes('save');
    }

    /** Writes: refused until step F7c. */
    public function forget(string $scope): void
    {
        throw UnsupportedPersistence::writes('forget');
    }
}
