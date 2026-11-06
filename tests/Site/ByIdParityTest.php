<?php

namespace Omnibridge\Eloquent\Tests\Site;

require_once __DIR__.'/autoload.php';

use Doctrine\ORM\EntityManagerInterface;
use Omnibridge\Doctrine\Omnibase\DoctrineBackend;
use Omnibridge\Doctrine\Omnibase\DoctrineUnitOfWork;
use Omnibridge\Eloquent\Connections;
use Omnibridge\Eloquent\EntityMap;
use Omnibridge\Eloquent\Omnibase\EloquentPersistence;
use Omnibridge\Eloquent\Tests\Support\Normaliser;
use Omnibridge\Schema\Inheritance;
use Omnibridge\Schema\Relation;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Every entity of an omnibase application - the core's and its own - read by id through Doctrine and through
 * omnibridge/eloquent on the same database, the same connection, and compared once normalised (Normaliser:
 * the fields, the to-one associations' class and id, the to-many associations' members) - plan "omnibase
 * agnostic", step F7b. Read only: nothing is written.
 *
 * Run in the application's Symfony test kernel, with the package copied into its vendor (autoload.php):
 *
 *     OMNIBRIDGE_ELOQUENT_SITE=1 php bin/phpunit vendor/omnibridge/eloquent/tests/Site/ByIdParityTest.php
 *
 * For each class, a sample of the rows that are exactly of that class (the first, the last and some between,
 * OMNIBRIDGE_ELOQUENT_SAMPLE of them, 5 by default), first with every scope off on both sides, then with the
 * trash scope on (Doctrine's TrashFilter, Eloquent's TrashScope). The report - per class: compared, equal,
 * the differences, the reason a class is not read - is written to var/eloquent-by-id.json.
 */
final class ByIdParityTest extends KernelTestCase
{
    public function testEveryEntityReadByIdIsDoctrinesOnTheSitesData(): void
    {
        if (!getenv('OMNIBRIDGE_ELOQUENT_SITE')) {
            self::markTestSkipped('Run in an application with OMNIBRIDGE_ELOQUENT_SITE=1.');
        }
        self::bootKernel();
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get('doctrine')->getManager();
        $projectDir = (string) static::getContainer()->getParameter('kernel.project_dir');
        $connection = $entityManager->getConnection();
        $doctrine = new DoctrineBackend(new DoctrineUnitOfWork($entityManager));
        $sample = (int) (getenv('OMNIBRIDGE_ELOQUENT_SAMPLE') ?: 5);

        $report = ['database' => $connection->getDatabase(), 'driver' => $connection->getNativeConnection()->getAttribute(\PDO::ATTR_DRIVER_NAME), 'passes' => []];
        foreach (['no scope' => [], 'trash scope' => ['trash']] as $pass => $scopes) {
            foreach (['trash', 'vault'] as $scope) {
                $doctrine->disableScope($scope);
            }
            foreach ($scopes as $scope) {
                $doctrine->enableScope($scope);
            }
            $eloquent = EloquentPersistence::create(Connections::pdo($connection->getNativeConnection(), (string) $connection->getDatabase()), ['project_dir' => $projectDir]);
            foreach ($scopes as $scope) {
                $eloquent->backend()->enableScope($scope);
            }
            $store = $eloquent->store();
            $normaliser = new Normaliser($store->mapping()->description);
            $classes = [];
            foreach ($store->mapping()->classes() as $class) {
                $entry = ['compared' => 0, 'equal' => 0, 'differences' => [], 'ids' => []];
                try {
                    if ((new \ReflectionClass($class))->isAbstract()) {
                        $classes[$class] = $entry + ['not read' => 'abstract: no row is of this class itself'];
                        continue;
                    }
                    $map = $store->mapping()->of($class);
                    $entry['ids'] = $ids = $this->sample($store->connection(), $map, $sample);
                    if ([] === $ids) {
                        $classes[$class] = $entry + ['not read' => 'no row of this class in the database'];
                        continue;
                    }
                    foreach ($ids as $id) {
                        $entityManager->clear();
                        $store->clear();
                        $left = $entityManager->find($class, $id);
                        $right = $store->find($class, $id);
                        ++$entry['compared'];
                        if (null === $left && null === $right) {
                            ++$entry['equal'];
                            continue;
                        }
                        $a = null === $left ? null : $normaliser->entity($left);
                        $b = null === $right ? null : $normaliser->entity($right);
                        [$differences, $postLoad] = self::split(self::difference($a, $b));
                        if ([] === $differences) {
                            ++$entry['equal'];
                        } else {
                            $entry['differences'][(string) $id] = $differences;
                        }
                        if ([] !== $postLoad) {
                            $entry['postLoad'][(string) $id] = $postLoad;
                        }
                    }
                } catch (\Throwable $error) {
                    $entry['error'] = $error::class.': '.$error->getMessage().' at '.basename($error->getFile()).':'.$error->getLine();
                }
                $classes[$class] = $entry;
            }
            $entityManager->clear();
            $report['passes'][$pass] = [
                'classes' => \count($classes),
                'compared' => array_sum(array_column($classes, 'compared')),
                'equal' => array_sum(array_column($classes, 'equal')),
                'unequal' => array_keys(array_filter($classes, static fn (array $entry): bool => [] !== $entry['differences'] || isset($entry['error']))),
                'postLoad only' => array_keys(array_filter($classes, static fn (array $entry): bool => isset($entry['postLoad']))),
                'not read' => array_map(static fn (array $entry): string => $entry['not read'], array_filter($classes, static fn (array $entry): bool => isset($entry['not read']))),
                'by class' => $classes,
            ];
        }
        $doctrine->disableScope('trash');
        @mkdir($projectDir.'/var', 0777, true);
        file_put_contents($projectDir.'/var/eloquent-by-id.json', json_encode($report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR)."\n");

        foreach ($report['passes'] as $pass => $result) {
            self::assertSame([], $result['unequal'], $pass.': '.json_encode(array_intersect_key($result['by class'], array_flip($result['unequal'])), \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR));
            self::assertGreaterThan(0, $result['compared']);
        }
    }

    /**
     * The entity classes the site's data holds no row of (most of the core's), a row written for each. In one
     * transaction, rolled back - nothing stays: for each such class that is no abstract one, a row in
     * each of its tables under a negative identifier, from -1000 down (an auto-increment counter only moves for a greater one),
     * every column given a value of its type (an enumeration's first value, JSON, a serialized array, base64...),
     * every to-one association pointing at a row of its target (the site's first, else the one written for it),
     * each many-to-many association holding that row; foreign keys unchecked for the transaction's session. Then each row read by id on both sides.
     */
    public function testEveryEntityClassReadByIdOnARowWrittenForIt(): void
    {
        if (!getenv('OMNIBRIDGE_ELOQUENT_SITE')) {
            self::markTestSkipped('Run in an application with OMNIBRIDGE_ELOQUENT_SITE=1.');
        }
        self::bootKernel();
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get('doctrine')->getManager();
        $projectDir = (string) static::getContainer()->getParameter('kernel.project_dir');
        $connection = $entityManager->getConnection();
        $pdo = $connection->getNativeConnection();
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $doctrine = new DoctrineBackend(new DoctrineUnitOfWork($entityManager));
        foreach (['trash', 'vault'] as $scope) {
            $doctrine->disableScope($scope);
        }
        $eloquent = EloquentPersistence::create(Connections::pdo($pdo, (string) $connection->getDatabase()), ['project_dir' => $projectDir]);
        $store = $eloquent->store();
        $description = $store->mapping()->description;
        $types = \Base\Database\Schema\EntitySchema::columnTypes(['project_dir' => $projectDir]);
        $normaliser = new Normaliser($description);

        // The classes the site's data holds no row of: the others are read on the site's own rows (the test above).
        // A row is written for another class too when a unique join column needs a target row of its own (it is not
        // compared: the site's rows of that class are).
        $all = array_values(array_filter($store->mapping()->classes(), static fn (string $class): bool => !(new \ReflectionClass($class))->isAbstract()));
        $concrete = array_values(array_filter($all, fn (string $class): bool => [] === $this->sample($store->connection(), $store->mapping()->of($class), 1)));
        $queue = $concrete;
        $ids = [];
        foreach ($all as $index => $class) {
            $ids[$class] = -(1000 + $index);
        }
        // A to-one association points at a row the site has of its target (its data has no cycle, which Doctrine's
        // eager reads of a hierarchy would follow for ever), else at the row written for the target - another class's.
        $existing = [];
        $idOf = static function (string $class, string $owner, bool $unique = false) use ($ids, $description, $store, &$existing, &$queue): ?int {
            $map = $store->mapping()->of($class);
            $class = $map->className();
            if (!\array_key_exists($class, $existing)) {
                $found = $store->query($map)->where($map->idColumns()[0], '>', 0)->orderBy($map->idColumns()[0])->value($map->idColumns()[0]);
                $existing[$class] = null === $found ? null : (int) $found;
            }
            // A unique join column (a one-to-one, a column of a unique index) cannot take a row the site's own rows point at already.
            if (null !== $existing[$class] && !$unique) {
                return $existing[$class];
            }
            // The rows written point only at rows written before them (a smaller number), so they hold no cycle either;
            // another class's if there is none.
            $candidates = array_values(array_filter(array_merge([$class], $description->entities[$class]->subclasses ?? []), static fn (string $candidate): bool => isset($ids[$candidate]) && $candidate !== $owner));
            $chosen = null;
            foreach ($candidates as $candidate) {
                if ($ids[$candidate] > $ids[$owner]) {
                    $chosen = $candidate;
                    break;
                }
            }
            $chosen ??= $candidates[0] ?? null;
            if (null !== $chosen && !\in_array($chosen, $queue, true)) {
                $queue[] = $chosen;
            }

            return null === $chosen ? null : $ids[$chosen];
        };

        $classes = [];
        $connection->beginTransaction();
        try {
            $connection->executeStatement('mysql' === $driver ? 'SET FOREIGN_KEY_CHECKS = 0' : 'PRAGMA defer_foreign_keys = ON');
            for ($written = 0; $written < \count($queue); ++$written) {
                $class = $queue[$written];
                $map = $store->mapping()->of($class);
                $id = $ids[$class];
                $foreign = [];
                $uniqueColumns = [];
                foreach ($map->tables as $table) {
                    foreach ($description->table($table)->indexes as $index) {
                        if ($index->unique) {
                            $uniqueColumns[$table] = array_merge($uniqueColumns[$table] ?? [], $index->columns);
                        }
                    }
                }
                // A class the site has a row of (a target written for a unique join column) gets a copy of that row: its
                // values are real ones (made-up ones in a thread's row send Doctrine's read into an endless loop).
                $real = $this->sample($store->connection(), $map, 1)[0] ?? null;
                foreach ($map->entity->relations as $relation) {
                    if (!$relation->isToOne() || !$relation->owning) {
                        continue;
                    }
                    $unique = $relation->joinColumns[0]->unique || Relation::ONE_TO_ONE === $relation->kind
                        || \in_array($map->joinColumn($relation), $uniqueColumns[$map->tableOf($relation->property)] ?? [], true);
                    if (null !== $real && !$unique) {
                        continue;
                    }
                    // A nullable association is left null: the row's own values are read, not a graph.
                    $foreign[$map->tableOf($relation->property)][$map->joinColumn($relation)] = $relation->joinColumns[0]->nullable ? null : $idOf($relation->target, $class, $unique);
                }
                $enumOf = [];
                foreach ($map->entity->fields as $property => $field) {
                    if (null !== $field->enum) {
                        $enumOf[$map->tableOf($property)][$field->column] = $field->enum;
                    }
                }
                foreach ($map->tables as $table) {
                    $copy = null === $real ? false : $connection->fetchAssociative('SELECT * FROM '.$connection->quoteSingleIdentifier($table).' WHERE id = ?', [$real]);
                    $values = [];
                    foreach ($description->table($table)->columns as $name => $column) {
                        $values[$name] = match (true) {
                            \in_array($name, array_map(static fn ($field) => $field->column, $map->identifier), true) => $id,
                            $table === $map->tables[0] && $name === $map->discriminator() => (string) array_search($class, $map->inheritance->map, true),
                            \array_key_exists($name, $foreign[$table] ?? []) => $foreign[$table][$name],
                            false !== $copy && \is_string($copy[$name] ?? null) && \in_array($name, $uniqueColumns[$table] ?? [], true) => substr('f7b'.abs($id).$copy[$name], 0, max(1, $column->length ?? 255)),
                            false !== $copy => $copy[$name] ?? null,
                            isset($enumOf[$table][$name]) => $enumOf[$table][$name]::cases()[0]->value,
                            // An enumeration takes a value the site's rows hold, else its first.
                            'enum' === $types->resolve($column)->type && null !== ($held = $connection->fetchOne('SELECT '.$connection->quoteSingleIdentifier($name).' FROM '.$connection->quoteSingleIdentifier($table).' WHERE '.$connection->quoteSingleIdentifier($name).' IS NOT NULL AND id > 0 LIMIT 1') ?: null) => $held,
                            default => self::valueOf($column, $types, $id),
                        };
                    }
                    $connection->insert($connection->quoteSingleIdentifier($table), array_combine(array_map($connection->quoteSingleIdentifier(...), array_keys($values)), array_values($values)));
                }
                foreach ($map->entity->relations as $relation) {
                    if (Relation::MANY_TO_MANY === $relation->kind && $relation->owning && null !== $relation->joinTable && null !== ($target = $idOf($relation->target, $class))) {
                        $connection->insert($connection->quoteSingleIdentifier($relation->joinTable->name), [
                            $connection->quoteSingleIdentifier($relation->joinTable->joinColumns[0]->name) => $id,
                            $connection->quoteSingleIdentifier($relation->joinTable->inverseJoinColumns[0]->name) => $target,
                        ]);
                    }
                }
            }
            self::trace($projectDir, 'rows written');
            foreach ($concrete as $class) {
                $entry = ['id' => $ids[$class]];
                try {
                    $entityManager->clear();
                    $store->clear();
                    self::trace($projectDir, $class.': Doctrine');
                    $left = $entityManager->find($class, $ids[$class]);
                    $a = null === $left ? null : $normaliser->entity($left);
                    self::trace($projectDir, $class.': Eloquent');
                    $right = $store->find($class, $ids[$class]);
                    $b = null === $right ? null : $normaliser->entity($right);
                    [$differences, $postLoad] = null === $a ? [['doctrine' => 'not found', 'eloquent' => null === $b ? 'not found' : 'found'], []] : self::split(self::difference($a, $b));
                    $entry['equal'] = [] === $differences;
                    if (!$entry['equal']) {
                        $entry['differences'] = $differences;
                    }
                    if ([] !== $postLoad) {
                        $entry['postLoad'] = $postLoad;
                    }
                } catch (\Throwable $error) {
                    $entry['equal'] = false;
                    $entry['error'] = $error::class.': '.$error->getMessage().' at '.basename($error->getFile()).':'.$error->getLine();
                }
                $classes[$class] = $entry;
            }
        } finally {
            $entityManager->clear();
            $connection->rollBack();
            if ('mysql' === $driver) {
                $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
            }
        }

        $unequal = array_keys(array_filter($classes, static fn (array $entry): bool => !$entry['equal']));
        $report = ['database' => $connection->getDatabase(), 'driver' => $driver, 'classes' => \count($classes), 'equal' => \count($classes) - \count($unequal), 'unequal' => $unequal,
            'postLoad only' => array_keys(array_filter($classes, static fn (array $entry): bool => isset($entry['postLoad']))), 'by class' => $classes];
        file_put_contents($projectDir.'/var/eloquent-by-id-written.json', json_encode($report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR)."\n");
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM '.$connection->quoteSingleIdentifier($description->entity($concrete[0])->table ?? $store->mapping()->of($concrete[0])->tables[0]).' WHERE id <= -1000'), 'the rows written are gone');
        self::assertSame([], $unequal, json_encode(array_intersect_key($classes, array_flip($unequal)), \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /** A line in var/eloquent-by-id.trace with OMNIBRIDGE_ELOQUENT_TRACE=1: where a long run is. */
    private static function trace(string $projectDir, string $line): void
    {
        if (getenv('OMNIBRIDGE_ELOQUENT_TRACE')) {
            file_put_contents($projectDir.'/var/eloquent-by-id.trace', date('H:i:s ').$line."\n", \FILE_APPEND);
        }
    }

    /** A value of the column's type for a row written by the test (unique where text is). */
    private static function valueOf(\Omnibridge\Schema\Column $column, \Omnibridge\Schema\Database\ColumnTypes $types, int $id): mixed
    {
        $text = static fn (string $value): string => null !== $column->length ? substr($value, 0, max(1, $column->length)) : $value;
        $special = match ($column->type) {
            'array' => serialize(['f7b' => $id]),
            'passkey_base64' => base64_encode('f7b'.$id),
            'passkey_aaguid' => '00000000-0000-0000-0000-'.str_pad((string) abs($id), 12, '0', \STR_PAD_LEFT),
            'passkey_trust_path' => '{"type":"Webauthn\\\\TrustPath\\\\EmptyTrustPath"}',
            default => null,
        };
        if (null !== $special) {
            return $special;
        }
        $resolved = $types->resolve($column);
        // A nullable text is left null: a file's path (#[Uploader]) is looked for in the storage when Doctrine
        // reads it, and a made-up one waits for the storage's time-out.
        if ($column->nullable && \in_array($resolved->type, ['string', 'text', 'ascii_string'], true)) {
            return null;
        }

        return match ($resolved->type) {
            'enum', 'set' => $resolved->values[0] ?? '',
            'integer', 'smallint', 'bigint' => 7,
            'boolean' => 1,
            'float' => 1.5,
            'decimal' => '12.34',
            // An empty list: a made-up object in a positions column (#[OrderColumn]) sends Doctrine's postLoad ordering
            // into an endless loop.
            'json' => '[]',
            'simple_array' => 'f7b,'.abs($id),
            'datetime', 'datetimetz', 'datetime_immutable', 'datetimetz_immutable', 'utc_datetime', 'utc_datetime_immutable' => '2026-11-07 10:20:30',
            'date', 'date_immutable' => '2026-11-07',
            'time', 'time_immutable' => '10:20:30',
            'guid' => '00000000-0000-4000-8000-'.str_pad((string) abs($id), 12, '0', \STR_PAD_LEFT),
            // Unique per row even where the column is short (a locale): the row's number first.
            default => $text(base_convert((string) (abs($id) - 1000), 10, 36).'-f7b-'.$column->name),
        };
    }

    /**
     * The identifiers of rows exactly of the class (its own discriminator value): the first, the last and evenly
     * between, at most $count.
     *
     * @return list<int|string>
     */
    private function sample(\Illuminate\Database\Connection $connection, EntityMap $map, int $count): array
    {
        if (1 !== \count($map->identifier)) {
            throw new \LogicException('composite identifier: not sampled');
        }
        $query = $connection->table($map->tables[0].' as t0');
        foreach (\array_slice($map->tables, 1, null, true) as $index => $table) {
            $query->join($table.' as t'.$index, 't'.$index.'.'.$map->identifier[0]->column, '=', 't0.'.$map->identifier[0]->column);
        }
        if (null !== $map->inheritance) {
            $value = array_search($map->className(), $map->inheritance->map, true);
            if (false === $value) {
                return [];
            }
            $query->where('t0.'.$map->discriminator(), '=', $value);
        }
        $ids = $query->orderBy('t0.'.$map->identifier[0]->column)->pluck('t0.'.$map->identifier[0]->column)->all();
        if (\count($ids) <= $count || $count < 2) {
            return \array_slice(array_values($ids), 0, max(1, $count));
        }
        $picked = [];
        for ($i = 0; $i < $count; ++$i) {
            $picked[] = $ids[(int) round($i * (\count($ids) - 1) / ($count - 1))];
        }

        return array_values(array_unique($picked));
    }

    /**
     * The differences, and apart those a behaviour's postLoad hook makes on Doctrine's side only - omnibase's
     * #[OrderColumn] writes its positions back as JSON text after Doctrine decoded them: the same value, which the
     * store gives decoded, since it runs no lifecycle hook before step F7c.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private static function split(array $differences): array
    {
        $postLoad = [];
        foreach ($differences as $path => $values) {
            if (\is_array($values) && \is_string($values['doctrine'] ?? null) && json_validate($values['doctrine']) && json_decode($values['doctrine'], true) === ($values['eloquent'] ?? null)) {
                $postLoad[$path] = $values;
                unset($differences[$path]);
            }
        }

        return [$differences, $postLoad];
    }

    /** The first paths where two normalised entities differ, with both values. */
    private static function difference(mixed $a, mixed $b, string $path = '', array &$found = []): array
    {
        if (\count($found) >= 5) {
            return $found;
        }
        if (\is_array($a) && \is_array($b)) {
            foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $key) {
                self::difference($a[$key] ?? '(none)', $b[$key] ?? '(none)', $path.'/'.$key, $found);
            }

            return $found;
        }
        if ($a !== $b) {
            $found[$path ?: '/'] = ['doctrine' => $a, 'eloquent' => $b];
        }

        return $found;
    }
}
