<?php

namespace Omnibridge\Eloquent\Omnibase;

use Base\Database\Repository\Entities;
use Base\Database\Repository\Repositories;
use Omnibridge\Eloquent\EntityStore;

/**
 * omnibase's Repositories on the store (plan "omnibase agnostic", step F7b): the plain repository of any
 * entity (EloquentEntities), and the entities of identifiers - one query for those not read yet, the identity
 * map for the others.
 */
final class EloquentRepositories implements Repositories
{
    /** @var array<string, Entities<object>> */
    private array $repositories = [];

    public function __construct(private readonly EntityStore $store)
    {
    }

    public function of(string $class): Entities
    {
        return $this->repositories[$class] ??= new EloquentEntities($this->store, $class);
    }

    public function byIds(string $class, array $ids): array
    {
        return $this->store->findMany($class, array_values($ids));
    }

    public function oneById(string $class, mixed $id): ?object
    {
        return null === $id ? null : $this->store->find($class, $id);
    }
}
