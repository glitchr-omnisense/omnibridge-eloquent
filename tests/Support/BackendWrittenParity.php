<?php

namespace Omnibridge\Eloquent\Tests\Support;

use Base\Entity\Analytics\PageView;
use Base\Entity\Analytics\PageVisit;
use Base\Entity\Analytics\Visit;
use Base\Entity\Layout\Redirection;
use Base\Repository\Analytics\PageViews;
use Base\Repository\Analytics\PageVisits;
use Base\Repository\Analytics\Visits;
use Base\Repository\Layout\Redirections;
use Doctrine\ORM\EntityManagerInterface;
use Omnibridge\Eloquent\Omnibase\EloquentPersistence;

/**
 * The 14 repository methods no finder compiles (#[BackendOnly] on the core's interfaces, plan "omnibase agnostic",
 * step F7f), on omnibridge/doctrine and on omnibridge/eloquent, on the same database:
 *
 * - reads(): the ten reads - the breakdowns, PageVisits::countUnique(), Visits::returning() - on the rows already there,
 *   for windows (all time, thirty days, the latest day with a row) and page filters (none, the most viewed page, a list
 *   of two, an empty list, a page with no row);
 * - writes(): the four writes run alike on each side, in a transaction rolled back - incrementView() on the latest row's
 *   hour (an upsert that adds) and on new pages and sources, recordPresence() twice in an hour (the second ignored),
 *   Redirections::hit() twice on a redirection written for it -, then the rows they touched read back (without their
 *   identifiers: MySQL does not give back what a rolled-back insert took; the hit's moment to the minute) with the
 *   reads again on the window of the writes.
 */
final class BackendWrittenParity
{
    /** @return array<string, array{0: \Closure(): mixed, 1: \Closure(): mixed}> FinderParity::compare()'s scenarios */
    public static function reads(EntityManagerInterface $entityManager, EloquentPersistence $persistence, ?\DateTimeImmutable $from = null): array
    {
        [$doctrine, $eloquent] = self::sides($entityManager, $persistence);
        $connection = $entityManager->getConnection();
        $views = $entityManager->getClassMetadata(PageView::class)->getTableName();
        $pageVisits = $entityManager->getClassMetadata(PageVisit::class)->getTableName();
        $visits = $entityManager->getClassMetadata(Visit::class)->getTableName();
        $utc = new \DateTimeZone('UTC');

        $latest = $connection->fetchOne("SELECT MAX(date) FROM {$views}");
        $moments = null !== $from ? ['the writes\' hour' => $from] : array_filter([
            'all time' => new \DateTimeImmutable('2000-01-01 00:00:00', $utc),
            'thirty days' => (new \DateTimeImmutable('now', $utc))->modify('-30 days')->setTime(0, 0),
            'the latest day' => $latest ? new \DateTimeImmutable(substr((string) $latest, 0, 10).' 00:00:00', $utc) : null,
        ]);
        $pathsOf = static fn (string $table, string $order): array => array_map('strval', $connection->fetchFirstColumn("SELECT path FROM {$table} GROUP BY path ORDER BY {$order} DESC, path ASC LIMIT 2"));
        $viewed = null !== $from ? ['/f7f/a', '/f7f/b'] : $pathsOf($views, 'SUM(views)');
        $visited = null !== $from ? ['/f7f/a', '/f7f/b'] : $pathsOf($pageVisits, 'COUNT(*)');
        $filters = static fn (array $paths): array => array_filter([
            'every page' => [null],
            'the first page' => isset($paths[0]) ? [$paths[0]] : null,
            'two pages' => \count($paths) > 1 ? [$paths] : null,
            'no page (an empty list)' => [[]],
            'a page with no row' => ['/f7f/none'],
        ]);
        $types = array_map('strval', $connection->fetchFirstColumn("SELECT DISTINCT subject_type FROM {$visits} ORDER BY subject_type")) ?: ['visitor'];

        $scenarios = [];
        $add = static function (string $name, string $interface, string $method, array $arguments) use (&$scenarios, $doctrine, $eloquent): void {
            $scenarios[$name] = [
                static fn () => $doctrine($interface)->{$method}(...$arguments),
                static fn () => $eloquent($interface)->{$method}(...$arguments),
            ];
        };
        foreach ($moments as $moment => $since) {
            foreach (['dailyBreakdown', 'dailyBreakdownBySource', 'hourlyBreakdown', 'hourlyBreakdownBySource'] as $method) {
                foreach ($filters($viewed) as $filter => [$path]) {
                    $add("PageViews::{$method}, {$moment}, {$filter}", PageViews::class, $method, [$since, $path]);
                }
            }
            foreach (['countUnique', 'dailyBreakdown', 'hourlyBreakdown'] as $method) {
                foreach ($filters($visited) as $filter => [$path]) {
                    $add("PageVisits::{$method}, {$moment}, {$filter}", PageVisits::class, $method, [$since, $path]);
                }
            }
            foreach ($types as $type) {
                $add("Visits::dailyBreakdown, {$moment}, {$type}", Visits::class, 'dailyBreakdown', [$type, $since]);
                $add("Visits::hourlyBreakdown, {$moment}, {$type}", Visits::class, 'hourlyBreakdown', [$type, $since]);
            }
        }
        if (null === $from) {
            $add('PageVisits::countUnique, all time, no filter', PageVisits::class, 'countUnique', []);
            $now = new \DateTimeImmutable('now', $utc);
            foreach ($types as $type) {
                foreach (['a week against the one before' => ['-14 days', '-7 days'], 'a month against the one before' => ['-60 days', '-30 days'], 'all time against the latest day' => ['2000-01-01', null]] as $window => [$previous, $current]) {
                    $currentSince = null === $current ? ($moments['the latest day'] ?? $now) : $now->modify($current)->setTime(0, 0);
                    $add("Visits::returning, {$window}, {$type}", Visits::class, 'returning', [$type, $now->modify($previous)->setTime(0, 0), $currentSince]);
                }
            }
        }

        return $scenarios;
    }

    /**
     * The four writes, one side's: the rows they touched and the reads of their window.
     *
     * @param 'doctrine'|'eloquent' $side
     *
     * @return array<string, mixed>
     */
    public static function writes(EntityManagerInterface $entityManager, EloquentPersistence $persistence, string $side, \DateTimeImmutable $hour): array
    {
        [$doctrine, $eloquent] = self::sides($entityManager, $persistence);
        $repository = 'doctrine' === $side ? $doctrine : $eloquent;
        $connection = $entityManager->getConnection();
        $views = $entityManager->getClassMetadata(PageView::class)->getTableName();
        $pageVisits = $entityManager->getClassMetadata(PageVisit::class)->getTableName();
        $visits = $entityManager->getClassMetadata(Visit::class)->getTableName();
        $redirections = $entityManager->getClassMetadata(Redirection::class)->getTableName();
        $redirectionColumn = static fn (string $field): string => $entityManager->getClassMetadata(Redirection::class)->getColumnName($field);
        $utc = new \DateTimeZone('UTC');

        // The latest row's hour, counted once more (an upsert that adds), and new pages of each source.
        $latest = $connection->fetchAssociative("SELECT path, date FROM {$views} ORDER BY date DESC, path ASC LIMIT 1") ?: null;
        if (null !== $latest) {
            $repository(PageViews::class)->incrementView((string) $latest['path'], new \DateTimeImmutable((string) $latest['date'], $utc));
        }
        foreach ([PageView::SOURCE_HUMAN, PageView::SOURCE_HUMAN, PageView::SOURCE_BOT, PageView::SOURCE_AI] as $i => $source) {
            $repository(PageViews::class)->incrementView('/f7f/a', $hour->modify("+{$i} minutes"), $source);
        }
        $repository(PageViews::class)->incrementView('/f7f/b', $hour->modify('+1 hour'));
        $repository(PageViews::class)->incrementView(str_repeat('/f7f/long', 40), $hour);

        foreach ([['/f7f/a', 'f7f-1', 0], ['/f7f/a', 'f7f-1', 30], ['/f7f/b', 'f7f-1', 61], ['/f7f/a', 'f7f-2', 5]] as [$path, $subject, $minutes]) {
            $repository(PageVisits::class)->recordPresence($path, 'visitor', $subject, $hour->modify("+{$minutes} minutes"));
            $repository(Visits::class)->recordPresence('visitor', $subject, $hour->modify("+{$minutes} minutes"));
        }

        // A redirection written for the hits (the site has none), then hit twice.
        $redirection = new Redirection('/f7f/old', '/f7f/new');
        $entityManager->persist($redirection);
        $entityManager->flush();
        $id = $redirection->getId();
        $hit = 'doctrine' === $side ? $redirection : ($persistence->repositories()->implementing(Redirections::class)->find($id) ?? throw new \LogicException('The redirection was not read by Eloquent.'));
        $repository(Redirections::class)->hit($hit);
        $repository(Redirections::class)->hit($hit);

        $since = $hour->format('Y-m-d H:i:s');
        $rows = static fn (string $sql, array $params = []): array => array_map(
            static function (array $row): array {
                unset($row['id']);
                ksort($row);

                return array_map(static fn ($value) => null === $value ? null : (string) $value, $row);
            },
            $connection->fetchAllAssociative($sql, $params),
        );

        return [
            'page views' => $rows("SELECT * FROM {$views} WHERE path LIKE '/f7f/%' OR (path = ? AND date = ?) ORDER BY path, date, source", [$latest['path'] ?? '', $latest['date'] ?? '']),
            'page visits' => $rows("SELECT * FROM {$pageVisits} WHERE date >= ? AND subject_id LIKE 'f7f-%' ORDER BY path, date, subject_id", [$since]),
            'visits' => $rows("SELECT * FROM {$visits} WHERE date >= ? AND subject_id LIKE 'f7f-%' ORDER BY date, subject_id", [$since]),
            'the redirection' => array_map(
                static fn (array $row): array => ['hits' => $row['hits'], 'last_hit_at' => null === $row['last_hit_at'] ? null : substr($row['last_hit_at'], 0, 16)],
                $rows("SELECT {$redirectionColumn('hits')} AS hits, {$redirectionColumn('lastHitAt')} AS last_hit_at FROM {$redirections} WHERE {$redirectionColumn('id')} = ?", [$id]),
            ),
            'the entity hit' => $hit->getHits(),
            'the reads of their window' => array_map(
                static fn (array $scenario) => FinderParity::normalise(('doctrine' === $side ? $scenario[0] : $scenario[1])()),
                self::reads($entityManager, $persistence, $hour),
            ),
        ];
    }

    /**
     * Both parts on one database: the reads compared (FinderParity's outcomes; $known: a scenario's name start => the
     * reason it may differ), then the writes run on each side in a transaction of Doctrine's connection - the one PDO
     * both write through - rolled back, and compared. The report goes to $report.
     *
     * @param array<string, string> $known
     *
     * @return array{reads: array<string, array<string, mixed>>, writes: array{doctrine: array<string, mixed>, eloquent: array<string, mixed>}}
     */
    public static function run(EntityManagerInterface $entityManager, EloquentPersistence $persistence, string $report, array $known = []): array
    {
        $scenarios = self::reads($entityManager, $persistence);
        foreach ($scenarios as $name => $scenario) {
            foreach ($known as $start => $reason) {
                if (str_starts_with($name, $start)) {
                    $scenarios[$name] = [$scenario[0], $scenario[1], false, $reason];
                }
            }
        }
        $reads = FinderParity::compare($scenarios);

        $hour = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+2 days')->setTime(10, 0);
        $writes = [];
        foreach (['doctrine', 'eloquent'] as $side) {
            $connection = $entityManager->getConnection();
            $connection->beginTransaction();
            try {
                $writes[$side] = self::writes($entityManager, $persistence, $side, $hour);
            } finally {
                $connection->rollBack();
                $entityManager->clear();
                $persistence->store()->clear();
            }
        }

        @mkdir(\dirname($report), 0777, true);
        file_put_contents($report, json_encode(['reads' => ['counts' => FinderParity::counts($reads), 'scenarios' => $reads], 'writes equal' => $writes['doctrine'] === $writes['eloquent'], 'writes' => $writes], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR)."\n");

        return ['reads' => $reads, 'writes' => $writes];
    }

    /** @return array{0: \Closure(class-string): object, 1: \Closure(class-string): object} each side's repository of an interface */
    private static function sides(EntityManagerInterface $entityManager, EloquentPersistence $persistence): array
    {
        $entities = [PageViews::class => PageView::class, PageVisits::class => PageVisit::class, Visits::class => Visit::class, Redirections::class => Redirection::class];

        return [
            static fn (string $interface): object => $entityManager->getRepository($entities[$interface]),
            static fn (string $interface): object => $persistence->repositories()->implementing($interface),
        ];
    }
}
