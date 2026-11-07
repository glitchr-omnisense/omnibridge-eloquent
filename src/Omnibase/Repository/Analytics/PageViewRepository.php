<?php

namespace Omnibridge\Eloquent\Omnibase\Repository\Analytics;

use Base\Entity\Analytics\PageView;
use Base\Repository\Analytics\PageViews;
use Omnibridge\Eloquent\Omnibase\EloquentEntities;

/**
 * omnibridge/doctrine's Analytics\PageViewRepository on Eloquent: countViews() and earliestDate() are finders (sumOf,
 * minOf), compiled by EloquentFinder (step F7b); the upsert and the breakdowns, declared #[BackendOnly] on the
 * interface (no finder: each persistence writes them), are omnibridge/doctrine's SQL sent through Eloquent's
 * connection (step F7f) - ON DUPLICATE KEY UPDATE on MySQL, ON CONFLICT elsewhere, SUM() grouped by DATE() or the hour.
 *
 * @extends EloquentEntities<PageView>
 */
class PageViewRepository extends EloquentEntities implements PageViews
{
    use Statements;

    /**
     * Atomic in the database (two requests the same hour both count), bucketed to the UTC hour, the source part of
     * the unique key - omnibridge/doctrine's statement.
     */
    public function incrementView(string $path, \DateTimeImmutable $date, string $source = PageView::SOURCE_HUMAN): void
    {
        $date = $date->setTimezone(new \DateTimeZone('UTC'));
        $hour = $date->setTime((int) $date->format('H'), 0, 0);
        $table = $this->table();

        $upsert = $this->onMySql()
            ? 'ON DUPLICATE KEY UPDATE views = views + 1'
            : "ON CONFLICT (path, date, source) DO UPDATE SET views = {$table}.views + 1";

        $this->store->connection()->statement(
            "INSERT INTO {$table} (path, date, source, views) VALUES (?, ?, ?, 1) {$upsert}",
            [mb_substr($path, 0, 255), $hour->format('Y-m-d H:i:s'), $source],
        );
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
        [$where, $bindings] = self::pathFilter($path);

        $breakdown = [];
        foreach ($this->rows(
            "SELECT DATE(date) AS date, SUM(views) AS views FROM {$this->table()}
             WHERE date >= ?{$where}
             GROUP BY DATE(date) ORDER BY DATE(date) ASC",
            [self::utc($since), ...$bindings],
        ) as $row) {
            $breakdown[$row['date']] = (int) $row['views'];
        }

        return $breakdown;
    }

    public function dailyBreakdownBySource(\DateTimeImmutable $since, string|array|null $path = null): array
    {
        [$where, $bindings] = self::pathFilter($path);

        return self::bySource($this->rows(
            "SELECT DATE(date) AS date, source, SUM(views) AS views FROM {$this->table()}
             WHERE date >= ?{$where}
             GROUP BY DATE(date), source ORDER BY DATE(date) ASC",
            [self::utc($since), ...$bindings],
        ));
    }

    public function hourlyBreakdown(\DateTimeImmutable $since, string|array|null $path = null): array
    {
        [$where, $bindings] = self::pathFilter($path);

        $breakdown = [];
        foreach ($this->rows(
            "SELECT date, SUM(views) AS views FROM {$this->table()}
             WHERE date >= ?{$where}
             GROUP BY date ORDER BY date ASC",
            [self::utc($since), ...$bindings],
        ) as $row) {
            $breakdown[$row['date']] = (int) $row['views'];
        }

        return $breakdown;
    }

    public function hourlyBreakdownBySource(\DateTimeImmutable $since, string|array|null $path = null): array
    {
        [$where, $bindings] = self::pathFilter($path);

        return self::bySource($this->rows(
            "SELECT date, source, SUM(views) AS views FROM {$this->table()}
             WHERE date >= ?{$where}
             GROUP BY date, source ORDER BY date ASC",
            [self::utc($since), ...$bindings],
        ));
    }

    /**
     * Every source's key on every bucket, 0 where it had no view.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, array<string, int>>
     */
    private static function bySource(array $rows): array
    {
        $sources = [PageView::SOURCE_HUMAN, PageView::SOURCE_BOT, PageView::SOURCE_AI];

        $breakdown = [];
        foreach ($rows as $row) {
            $breakdown[$row['date']] ??= array_fill_keys($sources, 0);
            $breakdown[$row['date']][$row['source']] = (int) $row['views'];
        }

        return $breakdown;
    }
}
