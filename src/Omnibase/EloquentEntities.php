<?php

namespace Omnibridge\Eloquent\Omnibase;

use Base\Database\Repository\Entities;
use Omnibridge\Eloquent\EntityMap;
use Omnibridge\Eloquent\EntityStore;
use Omnibridge\Eloquent\Omnibase\Finder\EloquentFinder;
use Omnibridge\Eloquent\Omnibase\Finder\FinderQuery;
use Omnibridge\Eloquent\UnsupportedPersistence;

/**
 * omnibase's repository on the store (plan "omnibase agnostic", step F7b): the core's Entities - find(), findAll(),
 * findBy(), findOneBy(), count() - and the finder language (findBySlug(), cacheOneByStateAndSlugStartingWith(),
 * countForParentByState()...), both compiled by EloquentFinder on Illuminate's query builder as omnibridge/doctrine's
 * ServiceEntityRepository compiles them on Doctrine: findBy()'s criteria array reads the words a name would (a list
 * IN, a Key without a join, a Group in parentheses, an association joined), its order the finder's (a path, FIELD(),
 * RAND()). A finder that hands back Doctrine's Query hands back a FinderQuery (getResult(), getOneOrNullResult()).
 *
 * The base of the core's repositories on Eloquent (Repository\*) and the repository of any other class.
 * persist(), remove() and flush() write: refused (UnsupportedPersistence) until step F7c.
 *
 * @template T of object
 *
 * @implements Entities<T>
 *
 * @method FinderQuery findAll()
 */
class EloquentEntities implements Entities
{
    private ?EloquentFinder $finder = null;

    /** @param class-string<T> $class */
    public function __construct(
        protected readonly EntityStore $store,
        protected readonly string $class,
    ) {
    }

    /** The class the repository reads (its rows: of it and its subclasses). */
    public function getClassName(): string
    {
        return $this->entityMap()->className();
    }

    /** The class's tables and columns (EntityMap). */
    public function entityMap(): EntityMap
    {
        return $this->store->mapping()->of($this->class);
    }

    public function finder(): EloquentFinder
    {
        return $this->finder ??= new EloquentFinder($this->store, $this->entityMap());
    }

    /** The finder language: findBy...(), findOneBy...(), cache...(), count...(), lengthOf...(), sumOf...()... */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->finder()->parse($method, $arguments);
    }

    public function find($id): ?object
    {
        return null === $id ? null : $this->store->find($this->class, $id);
    }

    public function findAll(): array
    {
        return $this->__call('findAll', [])->getResult();
    }

    public function findBy(array $criteria, ?array $orderBy = null, $limit = null, $offset = null): array
    {
        return $this->__call('findBy', [$criteria, $orderBy, $limit, $offset])->getResult();
    }

    public function findOneBy(array $criteria, ?array $orderBy = null): ?object
    {
        return $this->__call('findOneBy', [$criteria, $orderBy]);
    }

    public function count(array $criteria = []): int
    {
        return (int) $this->__call('count', [$criteria]);
    }

    /**
     * Query-by-Example (ServiceEntityRepository::findByExample()): the set fields and to-one associations of a select
     * object as findBy()'s criteria; a select setting none refused, rather than every row read.
     *
     * @return list<T>
     */
    public function findByExample(object $select, ?array $orderBy = null, $limit = null, $offset = null): array
    {
        $criteria = $this->criteriaFromSelect($select);
        if (empty($criteria)) {
            throw new \Exception(sprintf('findByExample() received a select (%s) with no queryable set fields — refusing to match the whole "%s" table. Use findAll() instead.', $select::class, $this->getClassName()));
        }

        return $this->findBy($criteria, $orderBy, $limit, $offset);
    }

    /** @return array<string, mixed> */
    public function criteriaFromSelect(object $select): array
    {
        return $this->finder()->subject()->criteriaFromSelect($select);
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
}
