<?php

namespace Omnibridge\Eloquent\Omnibase\Repository\Analytics;

/**
 * What the analytics repositories' own SQL needs of Eloquent's connection (plan "omnibase agnostic", step F7f): the
 * statements omnibridge/doctrine's repositories send through DBAL, sent through Illuminate's connection - the same
 * SQL, its table wrapped by the connection's grammar (its prefix), positional bindings (Illuminate does not expand a
 * named list), the platform read from the driver (MySQL and MariaDB on one side, SQLite and PostgreSQL on the other,
 * as DBAL's AbstractMySQLPlatform splits them).
 *
 * @internal
 */
trait Statements
{
    /** The class's table, as the connection's grammar writes it. */
    private function table(): string
    {
        return $this->store->connection()->getQueryGrammar()->wrapTable($this->entityMap()->tables[0]);
    }

    private function onMySql(): bool
    {
        return \in_array($this->store->connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    /** A moment as the analytics' columns hold it: UTC, to the second. */
    private static function utc(\DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * The rows of a SELECT, as arrays.
     *
     * @param list<mixed> $bindings
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $bindings): array
    {
        return array_map(static fn (object|array $row): array => (array) $row, $this->store->connection()->select($sql, $bindings));
    }

    /**
     * omnibridge/doctrine's path filter: one path (exact), a list (IN), null (every page). An empty list is "no page":
     * PageVisitRepository writes 1 = 0, PageViewRepository's DBAL expands it to IN (NULL) - no row either way.
     *
     * @param string|list<string>|null $path
     *
     * @return array{0: string, 1: list<mixed>} " AND ..." and its bindings
     */
    private static function pathFilter(string|array|null $path, string $column = 'path'): array
    {
        if (\is_array($path)) {
            if ([] === $path) {
                return [' AND 1 = 0', []];
            }

            return [' AND '.$column.' IN ('.implode(', ', array_fill(0, \count($path), '?')).')', array_values($path)];
        }
        if (null !== $path) {
            return [' AND '.$column.' = ?', [$path]];
        }

        return ['', []];
    }
}
