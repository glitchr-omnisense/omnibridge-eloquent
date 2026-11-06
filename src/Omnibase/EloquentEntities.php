<?php

namespace Omnibridge\Eloquent\Omnibase;

use Base\Database\Repository\Entities;
use Omnibridge\Eloquent\EloquentUnitOfWork;
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
 * persist(), remove() and flush() write through the unit of work (step F7c), as ServiceEntityRepository writes
 * through the entity manager: an entity of another class refused; a repository made without a unit of work
 * refuses to write (UnsupportedPersistence).
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
        protected readonly ?EloquentUnitOfWork $unitOfWork = null,
    ) {
    }

    /** The unit of work the repository writes through (refused when it was made without one). */
    public function unitOfWork(): EloquentUnitOfWork
    {
        return $this->unitOfWork ?? throw UnsupportedPersistence::operation('write through '.static::class, 'it was made without a unit of work (EloquentRepositories over an EloquentUnitOfWork)');
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
        $this->unitOfWork()->persist($this->ofClass($entity));
        if ($flush) {
            $this->unitOfWork()->flush();
        }
    }

    public function remove($entity, bool $flush = false): void
    {
        $this->unitOfWork()->remove($this->ofClass($entity));
        if ($flush) {
            $this->unitOfWork()->flush();
        }
    }

    public function flush(bool $autoclear = false)
    {
        $this->unitOfWork()->flush();
        if ($autoclear) {
            $this->store->clear();
        }
    }

    /** The entity, if it is of the repository's class (ServiceEntityRepository's message otherwise). */
    private function ofClass(mixed $entity): object
    {
        $class = $this->getClassName();
        if (!\is_object($entity) || !$entity instanceof $class) {
            throw new \InvalidArgumentException(\sprintf('Repository "%s" is expected "%s" entity, you passed "%s"', static::class, $class, \is_object($entity) ? $entity::class : 'null'));
        }

        return $entity;
    }
}
