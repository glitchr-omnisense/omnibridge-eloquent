<?php

namespace Omnibridge\Eloquent\Omnibase\Repository\Analytics;

use Base\Database\Repository\BackendOnly;
use Base\Entity\Analytics\Visit;
use Base\Repository\Analytics\Visits;
use Omnibridge\Eloquent\Omnibase\EloquentEntities;

/**
 * omnibridge/doctrine's Analytics\VisitRepository on Eloquent (plan "omnibase agnostic", step F7b): countUnique() is
 * a finder (distinctCountFor...), compiled by EloquentFinder; the INSERT IGNORE, the retention and the breakdowns
 * are native SQL declared #[BackendOnly('doctrine')] on the interface, refused aloud here (UnsupportedQueryException).
 *
 * @extends EloquentEntities<Visit>
 */
class VisitRepository extends EloquentEntities implements Visits
{
    public function recordPresence(string $subjectType, string $subjectId, \DateTimeImmutable $date): void
    {
        BackendOnly::refuse(Visits::class, __FUNCTION__, 'eloquent');
    }

    public function countUnique(string $subjectType, ?\DateTimeImmutable $since = null): int
    {
        // COUNT(DISTINCT subjectId) of the type, since the moment given.
        return (int) (null === $since
            ? $this->distinctCountForSubjectIdBySubjectType($subjectType)
            : $this->distinctCountForSubjectIdBySubjectTypeAndDateGreaterEqualTo($subjectType, $since));
    }

    public function returning(string $subjectType, \DateTimeImmutable $previousSince, \DateTimeImmutable $currentSince): array
    {
        BackendOnly::refuse(Visits::class, __FUNCTION__, 'eloquent');
    }

    public function dailyBreakdown(string $subjectType, \DateTimeImmutable $since): array
    {
        BackendOnly::refuse(Visits::class, __FUNCTION__, 'eloquent');
    }

    public function hourlyBreakdown(string $subjectType, \DateTimeImmutable $since): array
    {
        BackendOnly::refuse(Visits::class, __FUNCTION__, 'eloquent');
    }
}
