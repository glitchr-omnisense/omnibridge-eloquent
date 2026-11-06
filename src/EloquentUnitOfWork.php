<?php

namespace Omnibridge\Eloquent;

use Omnibridge\Persistence\UnitOfWork;

/**
 * omnisense/omnibridge's unit of work on the store, reading only (plan "omnibase agnostic", step F7b): what an
 * application asks of the entities it read - contains, reference, isNew, identifier, className, refresh - is
 * answered; what writes (persist, remove, flush, transactional, changes, recompute) is refused aloud with
 * UnsupportedPersistence until the writes are done, at step F7c.
 */
final class EloquentUnitOfWork implements UnitOfWork
{
    public function __construct(private readonly EntityStore $store)
    {
    }

    public function store(): EntityStore
    {
        return $this->store;
    }

    public function persist(object $entity): void
    {
        throw UnsupportedPersistence::writes('persist');
    }

    public function remove(object $entity): void
    {
        throw UnsupportedPersistence::writes('remove');
    }

    public function flush(): void
    {
        throw UnsupportedPersistence::writes('flush');
    }

    public function refresh(object $entity): void
    {
        if (!$this->store->identityMap()->contains($entity)) {
            throw new \InvalidArgumentException(\sprintf('The %s to refresh was not read through this unit of work.', $entity::class));
        }
        if (!$this->store->refresh($entity)) {
            throw EntityNotFound::of($entity::class, $this->store->identifier($entity));
        }
    }

    public function contains(object $entity): bool
    {
        return $this->store->identityMap()->contains($entity);
    }

    public function reference(string $class, mixed $id): object
    {
        return $this->store->reference($class, $id) ?? throw EntityNotFound::of($class, $id);
    }

    public function transactional(callable $work): mixed
    {
        throw UnsupportedPersistence::writes('transactional');
    }

    public function isNew(object $entity): bool
    {
        return !$this->store->identityMap()->contains($entity);
    }

    public function identifier(object $entity): mixed
    {
        return $this->store->mapping()->has($entity::class) ? $this->store->identifier($entity) : null;
    }

    public function className(object $entity): string
    {
        // A lazy ghost is of the entity's own class: nothing to see through.
        return $entity::class;
    }

    public function changes(object $entity): array
    {
        throw UnsupportedPersistence::writes('changes');
    }

    public function recompute(object $entity): void
    {
        throw UnsupportedPersistence::writes('recompute');
    }
}
