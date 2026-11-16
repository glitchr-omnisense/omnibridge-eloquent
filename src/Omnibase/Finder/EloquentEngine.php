<?php

namespace Omnibridge\Eloquent\Omnibase\Finder;

use Base\Database\Repository\Finder;
use Omnibridge\Eloquent\EntityMap;
use Omnibridge\Eloquent\EntityStore;

/**
 * The engine of one entity class on Eloquent: the finder language compiled on Illuminate's query builder
 * (EloquentFinder) and run on the store; a finder of several rows hands back a FinderQuery (the core's
 * Finder\Result).
 *
 * @internal
 */
final class EloquentEngine implements Finder
{
    private ?EloquentFinder $finder = null;

    /** @param class-string $class */
    public function __construct(private readonly EntityStore $store, private readonly string $class)
    {
    }

    public function className(): string
    {
        return $this->entityMap()->className();
    }

    /** The class's tables and columns. */
    public function entityMap(): EntityMap
    {
        return $this->store->mapping()->of($this->class);
    }

    public function finder(): EloquentFinder
    {
        return $this->finder ??= new EloquentFinder($this->store, $this->entityMap());
    }

    public function run(string $method, array $arguments): mixed
    {
        return DatabaseErrors::reading(fn (): mixed => $this->finder()->parse($method, $arguments));
    }

    public function find(mixed $id): ?object
    {
        return null === $id ? null : DatabaseErrors::reading(fn (): ?object => $this->store->find($this->class, $id));
    }

    public function clear(): void
    {
        $this->store->clear();
    }
}
