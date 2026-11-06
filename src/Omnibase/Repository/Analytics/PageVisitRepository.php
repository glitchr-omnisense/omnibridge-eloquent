<?php

namespace Omnibridge\Eloquent\Omnibase\Repository\Analytics;

use Base\Database\Repository\BackendOnly;
use Base\Entity\Analytics\PageVisit;
use Base\Repository\Analytics\PageVisits;
use Omnibridge\Eloquent\Omnibase\EloquentEntities;

/**
 * omnibridge/doctrine's Analytics\PageVisitRepository on Eloquent (plan "omnibase agnostic", step F7b): every method
 * is native SQL (INSERT IGNORE, COUNT over a DISTINCT subquery, DATE() groupings) declared
 * #[BackendOnly('doctrine')] on the interface, refused aloud here (UnsupportedQueryException); the plain reads
 * (find, findBy, count, the finders) are EloquentEntities'.
 *
 * @extends EloquentEntities<PageVisit>
 */
class PageVisitRepository extends EloquentEntities implements PageVisits
{
    public function recordPresence(string $path, string $subjectType, string $subjectId, \DateTimeImmutable $date): void
    {
        BackendOnly::refuse(PageVisits::class, __FUNCTION__, 'eloquent');
    }

    public function countUnique(?\DateTimeImmutable $since = null, string|array|null $path = null): int
    {
        BackendOnly::refuse(PageVisits::class, __FUNCTION__, 'eloquent');
    }

    public function dailyBreakdown(\DateTimeImmutable $since, string|array|null $path = null): array
    {
        BackendOnly::refuse(PageVisits::class, __FUNCTION__, 'eloquent');
    }

    public function hourlyBreakdown(\DateTimeImmutable $since, string|array|null $path = null): array
    {
        BackendOnly::refuse(PageVisits::class, __FUNCTION__, 'eloquent');
    }
}
