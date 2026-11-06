<?php

namespace Omnibridge\Eloquent\Omnibase\Repository\Hours;

use Base\Entity\Hours\SpecialDay;
use Base\Repository\Hours\SpecialDays;
use Omnibridge\Eloquent\Omnibase\EloquentEntities;

/**
 * omnibridge/doctrine's Hours\SpecialDayRepository on Eloquent (plan "omnibase agnostic", step F7b): the same finders, compiled by
 * EloquentFinder.
 *
 * @extends EloquentEntities<SpecialDay>
 */
class SpecialDayRepository extends EloquentEntities implements SpecialDays
{
    public function upcoming(\DateTimeInterface $from, int $limit = 50, ?string $scope = null): array
    {
        // The days are kept as Y-m-d text, which sorts and compares as the dates do.
        $from = $from->format('Y-m-d');

        return (null === $scope
            ? $this->findByEndsOnGreaterEqualToAndScopeNull($from, [], ['startsOn' => 'ASC'], $limit)
            : $this->findByEndsOnGreaterEqualTo($from, ['scope' => $scope], ['startsOn' => 'ASC'], $limit)
        )->getResult();
    }
}
