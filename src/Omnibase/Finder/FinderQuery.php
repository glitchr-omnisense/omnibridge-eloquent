<?php

namespace Omnibridge\Eloquent\Omnibase\Finder;

use Base\Service\Model\Pages;
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
 * It is also omnibase's Pages (Base\Service\Model\Pages: what Paginator::paginate() and Pagination page through, where
 * omnibridge/doctrine adapts Doctrine's Query with QueryPages): the entities counted once each, a page of them by
 * the query's own order (plan "omnibase agnostic", step F7d-2: a records picker's pages, RemoteSearch).
 *
 * @implements \IteratorAggregate<int, mixed>
 */
final class FinderQuery implements \IteratorAggregate, Pages
{
    private ?int $count = null;

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

    /**
     * The entities the query finds, each counted once, as QueryPages counts a DQL query's (the root entity's
     * identifier, distinct): its conditions, joins and groups kept, its order, limit and offset left out.
     */
    public function count(): int
    {
        if (null !== $this->count) {
            return $this->count;
        }
        $ids = clone $this->query;
        $ids->orders = $ids->unionOrders = null;
        $ids->limit = $ids->offset = $ids->unionLimit = $ids->unionOffset = null;
        $ids->bindings['order'] = [];
        $ids->bindings['select'] = [];
        $ids->columns = null;
        $ids->select($this->map->idColumns())->distinct();

        return $this->count = $this->query->newQuery()->fromSub($ids, 'omnibridge_pages')->count();
    }

    /**
     * One page of the results, by the query's order, $limit entities from the $offset-th, as QueryPages pages a DQL
     * query by its root entities: without a join, the rows themselves; with one (a to-many join repeats an entity), the
     * entities' identifiers in the query's order first, the page's then read.
     *
     * @return list<mixed>
     */
    public function slice(int $offset, int $limit): array
    {
        $page = clone $this->query;
        if ([] === (array) $this->query->joins) {
            $page->offset($offset)->limit($limit);

            return (new self($this->store, $this->map, $page, $this->entityKey, $this->columns))->getResult();
        }
        $ids = clone $this->query;
        $ids->limit = $ids->offset = null;
        $ids->bindings['select'] = [];
        $ids->columns = null;
        $columns = $this->map->idColumns();
        $ids->select($columns);
        $found = [];
        foreach ($ids->get() as $row) {
            $row = array_values((array) $row);
            $found[implode("\0", array_map('strval', $row))] ??= $row;
        }
        $keys = \array_slice(array_values($found), $offset, $limit);
        if ([] === $keys) {
            return [];
        }
        $page->limit = $page->offset = null;
        if (1 === \count($columns)) {
            $page->whereIn($columns[0], array_column($keys, 0));
        } else {
            $page->where(static function (Builder $any) use ($keys, $columns): void {
                foreach ($keys as $key) {
                    $any->orWhere(static function (Builder $one) use ($key, $columns): void {
                        foreach ($columns as $i => $column) {
                            $one->where($column, $key[$i]);
                        }
                    });
                }
            });
        }

        return (new self($this->store, $this->map, $page, $this->entityKey, $this->columns))->getResult();
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->getResult());
    }
}
