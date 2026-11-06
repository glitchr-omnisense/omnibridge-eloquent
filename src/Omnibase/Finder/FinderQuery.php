<?php

namespace Omnibridge\Eloquent\Omnibase\Finder;

use Illuminate\Database\Query\Builder;
use Omnibridge\Eloquent\EntityMap;
use Omnibridge\Eloquent\EntityStore;

/**
 * What a finder hands back where omnibridge/doctrine hands back Doctrine's Query (findBy...(), findAll(),
 * findLast...(), findAtMost...(), findPrevious...(), findNext...(), findRandomly...()): the compiled query, run
 * when its result is asked - getResult(), getOneOrNullResult() -, as often as it is asked.
 *
 * The result has Doctrine's shapes: the entities, in the order of their first row (a row repeated by a join of a
 * to-many association gives no second result, as Doctrine's ObjectHydrator keeps one per root entity; the LIMIT
 * counts rows, as Doctrine's SQL does); with other values selected (a grouping, selectAs, a count, a length), a row
 * per entity - ["entity" => the entity, ...] when grouped, [0 => the entity, ...] otherwise -, the values as the
 * database gives them, in the order they were selected, a repeated entity's row taking the last row's.
 *
 * @implements \IteratorAggregate<int, mixed>
 */
final class FinderQuery implements \IteratorAggregate
{
    /** @param array<string, string> $columns a mixed result's key => the column it is read from */
    public function __construct(
        private readonly EntityStore $store,
        private readonly EntityMap $map,
        private readonly Builder $query,
        private readonly int|string|null $entityKey,
        private readonly array $columns,
    ) {
    }

    /** @return list<mixed> */
    public function getResult(): array
    {
        $results = [];
        $seen = [];
        foreach ($this->store->results($this->map, $this->query) as [$entity, $record]) {
            // One result per entity, at its first row, as Doctrine's ObjectHydrator keeps a root entity met again (a
            // join of a to-many association): a later row's values replace the earlier ones' in a mixed result.
            $id = spl_object_id($entity);
            $index = $seen[$id] ??= \count($results);
            if (null === $this->entityKey) {
                $results[$index] = $entity;
                continue;
            }
            $results[$index] ??= [$this->entityKey => $entity];
            foreach ($this->columns as $key => $column) {
                $results[$index][$key] = $record[$column] ?? null;
            }
        }

        return $results;
    }

    /** The first result, null without one; more than one refused, as Doctrine's NonUniqueResultException does. */
    public function getOneOrNullResult(): mixed
    {
        $results = $this->getResult();
        if (\count($results) > 1) {
            throw new \UnexpectedValueException('More than one result was found for query although one row or none was expected.');
        }

        return $results[0] ?? null;
    }

    /** The SQL Illuminate runs (its placeholders), for a test or a log. */
    public function getSQL(): string
    {
        return $this->query->toSql();
    }

    /** @return list<mixed> the values bound, in order */
    public function getParameters(): array
    {
        return $this->query->getBindings();
    }

    public function getFirstResult(): int
    {
        return (int) ($this->query->offset ?? 0);
    }

    public function getMaxResults(): ?int
    {
        return $this->query->limit;
    }

    public function builder(): Builder
    {
        return $this->query;
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->getResult());
    }
}
