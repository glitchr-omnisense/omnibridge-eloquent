<?php

namespace Omnibridge\Eloquent\Tests\Support;

use Base\Database\Schema\EntitySchema;
use Doctrine\ORM\EntityManagerInterface;
use Omnibridge\Eloquent\Connections;
use Omnibridge\Eloquent\Omnibase\EloquentPersistence;
use Omnibridge\Eloquent\Omnibase\Finder\FinderQuery;

/**
 * What a finder parity test needs beside an omnibase application's Doctrine (plan "omnibase agnostic", step F7b):
 * omnibridge/eloquent on the same PDO - the same database, the same transaction -, the entities described from
 * where Doctrine's configuration finds them (omnibridge/symfony's InstalledEntities: the core's, the application's,
 * the bundles'), Doctrine's scopes enabled alike; each scenario run on both and compared once normalised, its
 * outcome recorded:
 *
 * - "equal": the same results, in the same order;
 * - "equal, another order": the same results in another order, from a query with no ORDER BY (the database's
 *   order: each persistence writes its own SQL);
 * - "both refuse": both threw, each message kept;
 * - "known difference": unequal for the reason the scenario gives (Doctrine refusing what Eloquent compiles: its
 *   FIELD() writing the values unquoted, RAND() on SQLite...);
 * - "unequal": anything else - a result on one side only, a different result.
 */
final class FinderParity
{
    public const EQUAL = 'equal';
    public const UNORDERED = 'equal, another order';
    public const REFUSED = 'both refuse';
    public const KNOWN = 'known difference';
    public const UNEQUAL = 'unequal';

    /** Eloquent on Doctrine's connection, with the scopes Doctrine has enabled (trash_filter, vault_filter and its environment). */
    public static function persistence(EntityManagerInterface $entityManager, string $projectDir): EloquentPersistence
    {
        $options = class_exists(\Omnibridge\Symfony\Omnibase\Console\InstalledEntities::class)
            ? \Omnibridge\Symfony\Omnibase\Console\InstalledEntities::options($entityManager, $projectDir)
            : ['project_dir' => $projectDir, 'paths' => EntitySchema::directories($projectDir)];
        $description = EntitySchema::describe($options['paths'], $options);
        $connection = $entityManager->getConnection();
        $persistence = EloquentPersistence::create(Connections::pdo($connection->getNativeConnection(), (string) $connection->getDatabase()), $options, $description);

        $filters = $entityManager->getFilters();
        if ($filters->isEnabled('trash_filter')) {
            $persistence->backend()->enableScope('trash');
        }
        if ($filters->isEnabled('vault_filter')) {
            $vault = $filters->getFilter('vault_filter');
            $persistence->backend()->enableScope('vault', ['environment' => method_exists($vault, 'getEnvironment') ? $vault->getEnvironment() : null]);
        }

        return $persistence;
    }

    /**
     * Each scenario on both persistences, compared.
     *
     * @param array<string, array{0: \Closure(): mixed, 1: \Closure(): mixed, 2?: bool, 3?: string|null}> $scenarios name => [Doctrine's call, Eloquent's call, whether its order is the database's, the reason they differ]
     *
     * @return array<string, array{outcome: string, doctrine: mixed, eloquent: mixed}>
     */
    public static function compare(array $scenarios, ?\Closure $between = null): array
    {
        $report = [];
        foreach ($scenarios as $name => $scenario) {
            [$doctrine, $eloquent] = $scenario;
            $unordered = $scenario[2] ?? false;
            $reason = $scenario[3] ?? null;
            $left = self::outcome($doctrine);
            $between && $between();
            $right = self::outcome($eloquent);
            $between && $between();
            $outcome = match (true) {
                isset($left['exception'], $right['exception']) => self::REFUSED,
                $left === $right => self::EQUAL,
                $unordered && \is_array($left) && \is_array($right) && !isset($left['exception']) && !isset($right['exception']) && self::sorted($left) === self::sorted($right) => self::UNORDERED,
                null !== $reason => self::KNOWN,
                default => self::UNEQUAL,
            };
            $report[$name] = ['outcome' => $outcome, 'doctrine' => $left, 'eloquent' => $right] + (null === $reason ? [] : ['reason' => $reason]);
        }

        return $report;
    }

    /** @param array<string, array{outcome: string}> $report */
    public static function counts(array $report): array
    {
        return array_count_values(array_column($report, 'outcome')) + [self::EQUAL => 0, self::UNORDERED => 0, self::REFUSED => 0, self::KNOWN => 0, self::UNEQUAL => 0];
    }

    /** @param array<string, array{outcome: string}> $report */
    public static function unequal(array $report): array
    {
        return array_filter($report, static fn (array $entry): bool => self::UNEQUAL === $entry['outcome']);
    }

    public static function write(string $file, array $report): void
    {
        @mkdir(\dirname($file), 0777, true);
        file_put_contents($file, json_encode(['counts' => self::counts($report), 'scenarios' => $report], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR)."\n");
    }

    /** What a call gave, comparable: entities by class and id, dates as text, a query's result, an exception's class and message. */
    private static function outcome(\Closure $call): mixed
    {
        try {
            return self::normalise($call());
        } catch (\Throwable $e) {
            return ['exception' => $e::class, 'message' => (string) preg_replace('~, called in \S+ on line \d+~', '', $e->getMessage())];
        }
    }

    public static function normalise(mixed $value): mixed
    {
        if ($value instanceof \Doctrine\ORM\Query || $value instanceof FinderQuery) {
            $value = $value->getResult();
        }
        if ($value instanceof \Doctrine\Common\Collections\Collection) {
            $value = $value->toArray();
        }
        if ($value instanceof \DateTimeInterface) {
            return ['@datetime' => $value->format(\DATE_ATOM)];
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if (\is_object($value)) {
            $class = $value::class;
            while (false !== get_parent_class($class) && (str_contains($class, '__CG__') || is_a($value, 'Doctrine\Persistence\Proxy'))) {
                $class = get_parent_class($class);
                break;
            }

            return ['@entity' => $class, 'id' => method_exists($value, 'getId') ? $value->getId() : spl_object_id($value)];
        }
        if (\is_array($value)) {
            return array_map(self::normalise(...), $value);
        }
        if (\is_float($value)) {
            return ['@float' => round($value, 6)];
        }

        return $value;
    }

    private static function sorted(array $rows): array
    {
        $keys = array_map(static fn (mixed $row): string => json_encode($row), $rows);
        sort($keys);

        return $keys;
    }
}
