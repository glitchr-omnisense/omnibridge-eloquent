<?php

namespace Omnibridge\Eloquent\Omnibase\Repository\Analytics;

use Base\Database\Repository\BackendOnly;
use Base\Entity\Analytics\PageView;
use Base\Repository\Analytics\PageViews;
use Omnibridge\Eloquent\Omnibase\EloquentEntities;

/**
 * omnibridge/doctrine's Analytics\PageViewRepository on Eloquent (plan "omnibase agnostic", step F7b): countViews()
 * and earliestDate() are finders (sumOf, minOf), compiled by EloquentFinder; the upsert and the breakdowns are
 * native SQL declared #[BackendOnly('doctrine')] on the interface, refused aloud here (UnsupportedQueryException).
 *
 * @extends EloquentEntities<PageView>
 */
class PageViewRepository extends EloquentEntities implements PageViews
{
    public function incrementView(string $path, \DateTimeImmutable $date, string $source = PageView::SOURCE_HUMAN): void
    {
        BackendOnly::refuse(PageViews::class, __FUNCTION__, 'eloquent');
    }

    public function countViews(?string $path = null, ?\DateTimeImmutable $since = null, ?string $source = null): int
    {
        $criteria = array_filter(['path' => $path, 'source' => $source], static fn ($value) => null !== $value);

        // SUM(views), null without a row.
        $views = null === $since ? $this->sumOfViewsBy($criteria) : $this->sumOfViewsByDateGreaterEqualTo($since, $criteria);

        return (int) ($views ?? 0);
    }

    public function earliestDate(): ?\DateTimeImmutable
    {
        $date = $this->minOfDate();

        // Stored in UTC, so read back as UTC; truncated to the day.
        return $date ? new \DateTimeImmutable(substr((string) $date, 0, 10), new \DateTimeZone('UTC')) : null;
    }

    public function dailyBreakdown(\DateTimeImmutable $since, string|array|null $path = null): array
    {
        BackendOnly::refuse(PageViews::class, __FUNCTION__, 'eloquent');
    }

    public function dailyBreakdownBySource(\DateTimeImmutable $since, string|array|null $path = null): array
    {
        BackendOnly::refuse(PageViews::class, __FUNCTION__, 'eloquent');
    }

    public function hourlyBreakdown(\DateTimeImmutable $since, string|array|null $path = null): array
    {
        BackendOnly::refuse(PageViews::class, __FUNCTION__, 'eloquent');
    }

    public function hourlyBreakdownBySource(\DateTimeImmutable $since, string|array|null $path = null): array
    {
        BackendOnly::refuse(PageViews::class, __FUNCTION__, 'eloquent');
    }
}
