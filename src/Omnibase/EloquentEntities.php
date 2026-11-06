<?php

namespace Omnibridge\Eloquent\Omnibase;

use Base\Database\Repository\Entities;
use Illuminate\Database\Query\Builder;
use Omnibridge\Eloquent\EntityMap;
use Omnibridge\Eloquent\EntityStore;
use Omnibridge\Eloquent\UnsupportedPersistence;

/**
 * omnibase's Entities (a repository's plain half) on the store, for a class no repository interface of the core
 * names (plan "omnibase agnostic", step F7b): find(), findAll(), and findBy(), findOneBy() and count() on a
 * criteria array as Doctrine's EntityRepository reads one - a field or a to-one association (an entity or its
 * identifier) equal to a value, IN a list, IS NULL for null - with an order on fields. persist(), remove() and
 * flush() write: refused (UnsupportedPersistence) until step F7c.
 *
 * @template T of object
 *
 * @implements Entities<T>
 */
final class EloquentEntities implements Entities
{
    public function __construct(
        private readonly EntityStore $store,
        private readonly string $class,
    ) {
    }

    public function find($id): ?object
    {
        return null === $id ? null : $this->store->find($this->class, $id);
    }

    public function findAll(): array
    {
        return $this->findBy([]);
    }

    public function findBy(array $criteria, ?array $orderBy = null, $limit = null, $offset = null): array
    {
        return $this->store->select($this->class, function (Builder $query, EntityMap $map) use ($criteria, $orderBy, $limit, $offset): void {
            $this->where($query, $map, $criteria);
            foreach ($orderBy ?? [] as $property => $direction) {
                $query->orderBy($map->column($property), 'DESC' === strtoupper((string) $direction) ? 'desc' : 'asc');
            }
            if (null !== $limit) {
                $query->limit((int) $limit);
            }
            if (null !== $offset) {
                $query->offset((int) $offset);
            }
        });
    }

    public function findOneBy(array $criteria, ?array $orderBy = null): ?object
    {
        return $this->findBy($criteria, $orderBy, 1)[0] ?? null;
    }

    public function count(array $criteria = []): int
    {
        $map = $this->store->mapping()->of($this->class);
        $query = $this->store->query($map);
        $this->where($query, $map, $criteria);

        return $query->count();
    }

    public function persist($entity, bool $flush = false): void
    {
        throw UnsupportedPersistence::writes('persist');
    }

    public function remove($entity, bool $flush = false): void
    {
        throw UnsupportedPersistence::writes('remove');
    }

    public function flush(bool $autoclear = false)
    {
        throw UnsupportedPersistence::writes('flush');
    }

    /** @param array<string, mixed> $criteria */
    private function where(Builder $query, EntityMap $map, array $criteria): void
    {
        foreach ($criteria as $property => $value) {
            $column = $map->column($property);
            $type = isset($map->entity->fields[$property])
                ? $map->entity->fields[$property]->type
                : $this->store->mapping()->of($map->entity->relation($property)->target)->identifier[0]->type;
            $bind = fn (mixed $value): mixed => $this->store->casts()->toDatabase($type, \is_object($value) && $this->store->mapping()->has($value::class) ? $this->store->identifier($value) : ($value instanceof \BackedEnum ? $value->value : $value));
            if (null === $value) {
                $query->whereNull($column);
            } elseif (\is_array($value)) {
                $query->whereIn($column, array_map($bind, array_values($value)));
            } else {
                $query->where($column, '=', $bind($value));
            }
        }
    }
}
