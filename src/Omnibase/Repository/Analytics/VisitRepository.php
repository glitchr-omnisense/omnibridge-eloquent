<?php

namespace Omnibridge\Eloquent\Omnibase\Repository\Analytics;

use Base\Entity\Analytics\Visit;
use Base\Repository\Analytics\Visits;
use Omnibridge\Eloquent\Omnibase\EloquentEntities;

/**
 * omnibridge/doctrine's Analytics\VisitRepository on Eloquent: countUnique() is a finder (distinctCountFor...), compiled
 * by EloquentFinder (step F7b); the INSERT IGNORE, the retention and the breakdowns, declared #[BackendOnly] on the
 * interface (no finder: each persistence writes them), are omnibridge/doctrine's SQL sent through Eloquent's connection
 * (step F7f).
 *
 * @extends EloquentEntities<Visit>
 */
class VisitRepository extends EloquentEntities implements Visits
{
    use Statements;

    public function recordPresence(string $subjectType, string $subjectId, \DateTimeImmutable $date): void
    {
        $date = $date->setTimezone(new \DateTimeZone('UTC'));
        $hour = $date->setTime((int) $date->format('H'), 0, 0);

        $this->store->connection()->statement(
            self::insertIgnore($this->onMySql(), "{$this->table()} (date, subject_type, subject_id) VALUES (?, ?, ?)"),
            [$hour->format('Y-m-d H:i:s'), $subjectType, mb_substr($subjectId, 0, 64)],
        );
    }

    /** "INSERT IGNORE INTO $into" on MySQL and MariaDB, "INSERT INTO $into ON CONFLICT DO NOTHING" elsewhere. */
    public static function insertIgnore(bool $onMySql, string $into): string
    {
        return $onMySql ? "INSERT IGNORE INTO {$into}" : "INSERT INTO {$into} ON CONFLICT DO NOTHING";
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
        $table = $this->table();
        $current = self::utc($currentSince);
        // The alias quoted: RETURNING is a keyword of SQLite since 3.35 (omnibridge/doctrine's bare alias fails there).
        $returning = $this->store->connection()->getQueryGrammar()->wrap('returning');

        $row = $this->rows(
            "SELECT COUNT(DISTINCT p.subject_id) AS previous,
                    COUNT(DISTINCT CASE WHEN EXISTS (
                        SELECT 1 FROM {$table} c
                        WHERE c.subject_type = p.subject_type AND c.subject_id = p.subject_id AND c.date >= ?
                    ) THEN p.subject_id END) AS {$returning}
             FROM {$table} p
             WHERE p.subject_type = ? AND p.date >= ? AND p.date < ?",
            [$current, $subjectType, self::utc($previousSince), $current],
        )[0] ?? [];

        return [
            'previous' => (int) ($row['previous'] ?? 0),
            'returning' => (int) ($row['returning'] ?? 0),
        ];
    }

    public function dailyBreakdown(string $subjectType, \DateTimeImmutable $since): array
    {
        $breakdown = [];
        foreach ($this->rows(
            "SELECT DATE(date) AS date, COUNT(DISTINCT subject_id) AS count FROM {$this->table()}
             WHERE subject_type = ? AND date >= ?
             GROUP BY DATE(date) ORDER BY DATE(date) ASC",
            [$subjectType, self::utc($since)],
        ) as $row) {
            $breakdown[$row['date']] = (int) $row['count'];
        }

        return $breakdown;
    }

    public function hourlyBreakdown(string $subjectType, \DateTimeImmutable $since): array
    {
        $breakdown = [];
        foreach ($this->rows(
            "SELECT date, COUNT(DISTINCT subject_id) AS count FROM {$this->table()}
             WHERE subject_type = ? AND date >= ?
             GROUP BY date ORDER BY date ASC",
            [$subjectType, self::utc($since)],
        ) as $row) {
            $breakdown[$row['date']] = (int) $row['count'];
        }

        return $breakdown;
    }
}
