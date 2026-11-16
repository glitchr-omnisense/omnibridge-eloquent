<?php

namespace Omnibridge\Eloquent\Omnibase;

use Base\Database\Mapping\DescriptionShapes;
use Base\Database\Mapping\EntityShapes;
use Base\Database\Repository\Finder;
use Base\Database\Repository\Finders;
use Omnibridge\Eloquent\EloquentUnitOfWork;
use Omnibridge\Eloquent\EntityStore;
use Omnibridge\Eloquent\Omnibase\Finder\EloquentEngine;
use Omnibridge\Eloquent\UnsupportedPersistence;
use Omnibridge\Persistence\UnitOfWork;

/**
 * omnibase's Finders on Eloquent: what the core's repositories (Base\Database\Repository\ServiceEntityRepository
 * and every class over it) are built from. The engine of a class is EloquentFinder on the store; sql() runs on the
 * store's connection; the shapes are the description's. Made without a unit of work, it reads and refuses to write.
 * The host binds it as Base\Database\Repository\Finders (EloquentPersistence::finders()).
 */
final class EloquentFinders implements Finders
{
    /** @var array<string, EloquentEngine> */
    private array $engines = [];

    private ?EntityShapes $shapes = null;


    public function __construct(private readonly EntityStore $store, private readonly ?EloquentUnitOfWork $unitOfWork = null)
    {
        // The behaviours' parts (bulk deletes, row locks...) for the unit of work, as DoctrineUnitOfWork registers Doctrine's.
        EloquentBehaviours::ensureRegistered();
    }

    /** @param class-string $class */
    public function of(string $class): Finder
    {
        return $this->engine($class);
    }

    /** @param class-string $class */
    public function engine(string $class): EloquentEngine
    {
        return $this->engines[$class] ??= new EloquentEngine($this->store, $class);
    }

    public function store(): EntityStore
    {
        return $this->store;
    }

    public function shapes(): EntityShapes
    {
        return $this->shapes ??= new DescriptionShapes($this->store->mapping()->description);
    }

    public function unitOfWork(): UnitOfWork
    {
        return $this->unitOfWork ?? throw UnsupportedPersistence::operation('write through a repository', 'its Finders were made without a unit of work');
    }

    public function backend(): string
    {
        return 'eloquent';
    }

    public function sql(string $sql, array $parameters = [], array $selections = []): array|int
    {
        $values = [];
        $written = '';
        $given = array_values($parameters);
        foreach (preg_split('/\{selection:(\d+)\}/', $sql, -1, \PREG_SPLIT_DELIM_CAPTURE) ?: [$sql] as $index => $piece) {
            if (1 === $index % 2) {
                [$class, $criteria] = $selections[(int) $piece] ?? throw new \LogicException(sprintf('No selection %s for %s.', $piece, $sql));
                [$subSql, $subValues] = $this->engine($class)->finder()->identifiers($criteria);
                $written .= $subSql;
                array_push($values, ...$subValues);
                continue;
            }
            $written .= $piece;
            for ($n = substr_count($piece, '?'); $n > 0; --$n) {
                $values[] = array_shift($given);
            }
        }
        $connection = $this->store->connection();

        return \Omnibridge\Eloquent\Omnibase\Finder\DatabaseErrors::reading(static fn (): array|int => 1 === preg_match('/^\s*(SELECT|WITH)\b/i', $written)
            ? array_map(static fn (mixed $row): array => (array) $row, $connection->select($written, $values))
            : $connection->affectingStatement($written, $values));
    }

    public function platform(): string
    {
        return match ($this->store->connection()->getDriverName()) {
            'sqlite' => 'sqlite',
            'pgsql' => 'pgsql',
            default => 'mysql',
        };
    }
}
