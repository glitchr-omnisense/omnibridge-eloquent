<?php

namespace Omnibridge\Eloquent\Omnibase\Repository\Analytics;

use Base\Entity\Analytics\PageVisit;
use Base\Repository\Analytics\PageVisits;
use Omnibridge\Eloquent\Omnibase\EloquentEntities;

/**
 * omnibridge/doctrine's Analytics\PageVisitRepository on Eloquent (step F7f): its four methods, declared #[BackendOnly]
 * on the interface (no finder: each persistence writes them), are omnibridge/doctrine's SQL sent through Eloquent's
 * connection - INSERT IGNORE (ON CONFLICT DO NOTHING off MySQL), COUNT(*) over a DISTINCT subquery, grouped by DATE()
 * or the hour. The plain reads (find, findBy, count, the finders) are EloquentEntities'.
 *
 * @extends EloquentEntities<PageVisit>
 */
class PageVisitRepository extends EloquentEntities implements PageVisits
{
    use Statements;

    public function recordPresence(string $path, string $subjectType, string $subjectId, \DateTimeImmutable $date): void
    {
        $date = $date->setTimezone(new \DateTimeZone('UTC'));
        $hour = $date->setTime((int) $date->format('H'), 0, 0);

        $this->store->connection()->statement(
            VisitRepository::insertIgnore($this->onMySql(), "{$this->table()} (date, path, subject_type, subject_id) VALUES (?, ?, ?, ?)"),
            [$hour->format('Y-m-d H:i:s'), mb_substr($path, 0, 255), $subjectType, mb_substr($subjectId, 0, 64)],
        );
    }

    public function countUnique(?\DateTimeImmutable $since = null, string|array|null $path = null): int
    {
        [$where, $bindings] = $this->filters($since, $path);

        return (int) ($this->rows(
            "SELECT COUNT(*) AS count FROM (SELECT DISTINCT path, subject_type, subject_id FROM {$this->table()}{$where}) pairs",
            $bindings,
        )[0]['count'] ?? 0);
    }

    public function dailyBreakdown(\DateTimeImmutable $since, string|array|null $path = null): array
    {
        [$where, $bindings] = $this->filters($since, $path);

        return $this->pairs(
            "SELECT visit_day AS date, COUNT(*) AS count
             FROM (SELECT DISTINCT DATE(date) AS visit_day, path, subject_type, subject_id FROM {$this->table()}{$where}) pairs
             GROUP BY visit_day ORDER BY visit_day ASC",
            $bindings,
        );
    }

    public function hourlyBreakdown(\DateTimeImmutable $since, string|array|null $path = null): array
    {
        [$where, $bindings] = $this->filters($since, $path);

        return $this->pairs(
            "SELECT date, COUNT(*) AS count
             FROM (SELECT DISTINCT date, path, subject_type, subject_id FROM {$this->table()}{$where}) pairs
             GROUP BY date ORDER BY date ASC",
            $bindings,
        );
    }

    /**
     * " WHERE ..." for a moment and a page filter (an empty list of pages is no page).
     *
     * @param string|list<string>|null $path
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function filters(?\DateTimeImmutable $since, string|array|null $path): array
    {
        $clauses = [];
        $bindings = [];
        if (null !== $since) {
            $clauses[] = 'date >= ?';
            $bindings[] = self::utc($since);
        }
        [$where, $pathBindings] = self::pathFilter($path);
        if ('' !== $where) {
            $clauses[] = substr($where, \strlen(' AND '));
            array_push($bindings, ...$pathBindings);
        }

        return [$clauses ? ' WHERE '.implode(' AND ', $clauses) : '', $bindings];
    }

    /**
     * @param list<mixed> $bindings
     *
     * @return array<string, int>
     */
    private function pairs(string $sql, array $bindings): array
    {
        $breakdown = [];
        foreach ($this->rows($sql, $bindings) as $row) {
            $breakdown[$row['date']] = (int) $row['count'];
        }

        return $breakdown;
    }
}
