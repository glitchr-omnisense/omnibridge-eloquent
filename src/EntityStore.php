<?php

namespace Omnibridge\Eloquent;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Omnibridge\Schema\Relation;

/**
 * The data mapper: rows of Eloquent's connection read into the application's
 * plain PHP entities, as a description maps them (Omnibridge\Schema), with no
 * Eloquent model - the entities are those Doctrine maps, and nothing in them
 * knows which backend read them.
 *
 * - The query of a class (query()): its tables joined from the root's
 *   (EntityMap), every column selected, the class's discriminator values for
 *   a single-table subclass, the store's conditions (a backend's scopes).
 * - The rows read (rows(), hydrate()): a row of a subclass gets the tables its
 *   class adds, read by identifier, then the entity of its class is made
 *   without its constructor - or found in the identity map, one object per row -
 *   and its properties written raw (no hook, no setter), as Doctrine writes them.
 * - Values through the Casts, a field mapped on a PHP enumeration (enumType) made one with from();
 *   associations as Doctrine gives them:
 *   - a to-one association: the entity already read, else a PHP 8.4 lazy ghost
 *     of the target's class with its identifier set (reading the identifier reads
 *     no row), else - a target with subclasses, whose class only its row says,
 *     or an inverse one-to-one - the entity read at once;
 *   - a to-many association: a LazyCollection (doctrine/collections'
 *     Collection) read on first use, ordered and indexed as declared.
 *
 * Reads only: writing is the unit of work's, at step F7c (UnsupportedPersistence).
 */
final class EntityStore
{
    /** @var array<string, \Closure(EntityMap, Builder, string): void> */
    private array $conditions = [];

    /** @var array<string, array<string, \ReflectionProperty>> */
    private array $properties = [];

    /** @var array<string, list<array{0: string, 1: string, 2: string}>> a map's (or extra tables') select: [expression, table, column] */
    private array $selections = [];

    private int $queries = 0;

    public function __construct(
        private readonly Connection $connection,
        private readonly Mapping $mapping,
        private readonly Casts $casts,
        private readonly IdentityMap $identityMap = new IdentityMap(),
    ) {
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    public function mapping(): Mapping
    {
        return $this->mapping;
    }

    public function casts(): Casts
    {
        return $this->casts;
    }

    public function identityMap(): IdentityMap
    {
        return $this->identityMap;
    }

    /** How many queries the store ran (the rows of a class, a subclass's tables, a collection), for the tests. */
    public function queries(): int
    {
        return $this->queries;
    }

    /**
     * A condition every query of the store adds while it is set (a backend's scope): it receives the class's map,
     * the query, and the prefix its tables are aliased under as EntityMap::alias() says - "t" in the class's own
     * query (its WHERE), another where the finder joins the class to another's query (the join's ON, as Doctrine
     * puts its SQL filters there).
     *
     * @param \Closure(EntityMap, Builder, string): void $condition
     */
    public function addCondition(string $name, \Closure $condition): void
    {
        $this->conditions[$name] = $condition;
    }

    public function removeCondition(string $name): void
    {
        unset($this->conditions[$name]);
    }

    public function hasCondition(string $name): bool
    {
        return isset($this->conditions[$name]);
    }

    /** Forgets every entity read: the next read makes new objects. */
    public function clear(): void
    {
        $this->identityMap->clear();
    }

    /**
     * The entity of that class and identifier, or null: the one read already when there is one (no query), else its
     * row read - of a subclass maybe, as the discriminator says.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    public function find(string $class, mixed $id): ?object
    {
        $map = $this->mapping->of($class);
        $id = $this->normaliseId($map, $id);
        $known = $this->identityMap->get($map->root, $id);
        if (null !== $known && !$this->isUninitialized($known)) {
            return $known instanceof $class || is_a($known, $map->className()) ? $known : null;
        }

        return $this->load($map, $id);
    }

    /**
     * The entities of those identifiers, in the order given, those not found left out.
     *
     * @param list<mixed> $ids
     *
     * @return list<object>
     */
    public function findMany(string $class, array $ids): array
    {
        $map = $this->mapping->of($class);
        if ([] === $ids) {
            return [];
        }
        if (1 !== \count($map->identifier)) {
            return array_values(array_filter(array_map(fn (mixed $id): ?object => $this->find($class, $id), $ids)));
        }
        $missing = [];
        foreach ($ids as $id) {
            $known = $this->identityMap->get($map->root, $id);
            if (null === $known || $this->isUninitialized($known)) {
                $missing[IdentityMap::key($id)] = $id;
            }
        }
        if ([] !== $missing) {
            $type = $map->identifier[0]->type;
            $this->select($class, fn (Builder $query) => $query->whereIn($map->idColumns()[0], array_map(fn (mixed $id): mixed => $this->casts->toDatabase($type, $id), array_values($missing))));
        }
        $found = [];
        foreach ($ids as $id) {
            $entity = $this->identityMap->get($map->root, $id);
            if (null !== $entity && !$this->isUninitialized($entity) && is_a($entity, $map->className())) {
                $found[] = $entity;
            }
        }

        return $found;
    }

    /**
     * The entity of that class and identifier without reading it: the one read already, else a lazy ghost that
     * reads its row when a field other than its identifier is asked (EntityNotFound then if there is none). A class
     * with subclasses is read at once, its row saying its class (null when there is no row), as Doctrine does.
     */
    public function reference(string $class, mixed $id): ?object
    {
        $map = $this->mapping->of($class);
        $id = $this->normaliseId($map, $id);

        return $this->identityMap->get($map->root, $id) ?? ($map->hasSubclasses() ? $this->load($map, $id) : $this->ghost($map, $id));
    }

    /**
     * The entities of a class whose rows the constraint keeps, in the order it gives (its where, order, limit...),
     * through the identity map.
     *
     * @param (\Closure(Builder, EntityMap): mixed)|null $constrain
     *
     * @return list<object>
     */
    public function select(string $class, ?\Closure $constrain = null): array
    {
        $map = $this->mapping->of($class);
        $query = $this->query($map);
        if (null !== $constrain) {
            $constrain($query, $map);
        }

        return $this->hydrate($map, $this->rows($map, $query));
    }

    /**
     * The query of a class: its tables joined (t0 the root's), every column selected under an alias rows() reads,
     * the discriminator values of a single-table subclass, the store's conditions.
     */
    public function query(EntityMap $map): Builder
    {
        $query = $this->connection->table($map->tables[0].' as t0');
        foreach (\array_slice($map->tables, 1, null, true) as $index => $table) {
            $query->join($table.' as t'.$index, function ($join) use ($map, $index): void {
                foreach ($map->identifier as $field) {
                    $join->on('t'.$index.'.'.$field->column, '=', 't0.'.$field->column);
                }
            });
        }
        $query->select(array_map(static fn (array $selected): string => $selected[0], $this->selection($map->className(), $map->tables, 't')));
        if (null !== ($values = $map->discriminatorValues())) {
            $query->whereIn('t0.'.$map->discriminator(), $values);
        }
        $this->constrain($map, $query, 't');

        return $query;
    }

    /**
     * The store's conditions (the scopes enabled) on a class whose tables are aliased under $prefix: its own query's
     * "t", or a join's (JoinClause is a Builder: the conditions go into its ON).
     */
    public function constrain(EntityMap $map, Builder $query, string $prefix): void
    {
        foreach ($this->conditions as $condition) {
            $condition($map, $query, $prefix);
        }
    }

    /**
     * The rows of a query made by query(), each as table => column => value, a subclass's own tables read and
     * added by identifier.
     *
     * @return list<array<string, array<string, mixed>>>
     */
    public function rows(EntityMap $map, Builder $query): array
    {
        return array_column($this->read($map, $query), 0);
    }

    /**
     * The entities of a query made by query() with the columns it selects besides theirs (the finder's counts,
     * lengths, groups and selected expressions): each [the entity, the record as the database gave it], in order,
     * one per record - a record repeated by a join gives the same entity again, as Doctrine's simple hydration does.
     *
     * @return list<array{0: object, 1: array<string, mixed>}>
     */
    public function results(EntityMap $map, Builder $query): array
    {
        $results = [];
        foreach ($this->read($map, $query) as [$row, $record]) {
            $results[] = [$this->entity($this->mapping->of($map->classOf($row)), $row), $record];
        }

        return $results;
    }

    /**
     * The rows of a query, each [table => column => value, the raw record], a subclass's own tables read and added.
     *
     * @return list<array{0: array<string, array<string, mixed>>, 1: array<string, mixed>}>
     */
    private function read(EntityMap $map, Builder $query): array
    {
        ++$this->queries;
        $selection = $this->selection($map->className(), $map->tables, 't');
        $rows = [];
        $records = [];
        foreach ($query->get() as $record) {
            $records[] = $record = (array) $record;
            $rows[] = self::unpack($record, $selection);
        }

        // A subclass's tables beyond the class's (joined inheritance), read per class by identifier.
        $byClass = [];
        foreach ($rows as $index => $row) {
            $class = $map->classOf($row);
            if ($class !== $map->className() && \count($this->mapping->of($class)->tables) > \count($map->tables)) {
                $byClass[$class][$index] = $row;
            }
        }
        foreach ($byClass as $class => $subRows) {
            $subMap = $this->mapping->of($class);
            if (1 !== \count($subMap->identifier)) {
                throw new \LogicException(\sprintf('%s has a composite identifier in a joined hierarchy: not read by omnibridge/eloquent yet.', $class));
            }
            $extra = \array_slice($subMap->tables, \count($map->tables));
            $column = $subMap->identifier[0]->column;
            $extraSelection = $this->selection($class.' extra '.\count($map->tables), $extra, 'e');
            $extraQuery = $this->connection->table($extra[0].' as e0');
            foreach (\array_slice($extra, 1, null, true) as $index => $table) {
                $extraQuery->join($table.' as e'.$index, 'e'.$index.'.'.$column, '=', 'e0.'.$column);
            }
            $ids = array_map(fn (array $row): mixed => $row[$map->tables[0]][$column], $subRows);
            ++$this->queries;
            $extraRows = [];
            foreach ($extraQuery->select(array_map(static fn (array $selected): string => $selected[0], $extraSelection))->whereIn('e0.'.$column, array_values($ids))->get() as $record) {
                $extraRow = self::unpack((array) $record, $extraSelection);
                $extraRows[IdentityMap::key($extraRow[$extra[0]][$column])] = $extraRow;
            }
            foreach ($subRows as $index => $row) {
                $rows[$index] += $extraRows[IdentityMap::key($row[$map->tables[0]][$column])] ?? [];
            }
        }

        return array_map(null, $rows, $records);
    }

    /**
     * The entities of rows read by rows(): each of its class, through the identity map.
     *
     * @param list<array<string, array<string, mixed>>> $rows
     *
     * @return list<object>
     */
    public function hydrate(EntityMap $map, array $rows): array
    {
        $entities = [];
        foreach ($rows as $row) {
            $entities[] = $this->entity($this->mapping->of($map->classOf($row)), $row);
        }

        return $entities;
    }

    /** The entity read again from its row, its unsaved changes lost; false when its row is gone. */
    public function refresh(object $entity): bool
    {
        $map = $this->mapping->of($entity::class);
        $id = $this->identifier($entity);
        $rows = $this->rows($map, $this->query($map)->where(fn (Builder $query) => $this->whereId($map, $query, $id)));

        if ([] === $rows) {
            return false;
        }
        $this->fill($map, $entity, $rows[0], $id);

        return true;
    }

    /** The entity's identifier: its value, or property => value for a composite one; read without loading a ghost. */
    public function identifier(object $entity): mixed
    {
        $map = $this->mapping->of($entity::class);
        $values = [];
        foreach ($map->identifier as $field) {
            $property = $this->property($field->declaredIn ?? $map->className(), $field->property);
            $values[$field->property] = $property->isInitialized($entity) || $this->isUninitialized($entity) ? $property->getRawValue($entity) : null;
        }

        return 1 === \count($values) ? reset($values) : $values;
    }

    /** Whether the entity is a lazy ghost no field of which was read yet. */
    public function isUninitialized(object $entity): bool
    {
        return (new \ReflectionClass($entity))->isUninitializedLazyObject($entity);
    }

    private function load(EntityMap $map, mixed $id, ?object $into = null): ?object
    {
        $rows = $this->rows($map, $this->query($map)->where(fn (Builder $query) => $this->whereId($map, $query, $id)));
        if ([] === $rows) {
            return null;
        }
        $concrete = $this->mapping->of($map->classOf($rows[0]));
        if (null !== $into && !$into instanceof ($concrete->className())) {
            throw new \UnexpectedValueException(\sprintf('The %s of identifier %s is a %s row.', $into::class, IdentityMap::key($id), $concrete->className()));
        }

        return $this->entity($concrete, $rows[0], $into);
    }

    private function whereId(EntityMap $map, Builder $query, mixed $id): void
    {
        foreach ($map->identifier as $field) {
            $value = \is_array($id) ? ($id[$field->property] ?? null) : $id;
            $query->where('t0.'.$field->column, '=', $this->casts->toDatabase($field->type, $value));
        }
    }

    /** @param array<string, array<string, mixed>> $row */
    private function entity(EntityMap $map, array $row, ?object $into = null): object
    {
        $id = $this->rowId($map, $row);
        $known = $into ?? $this->identityMap->get($map->root, $id);
        if (null !== $known && null === $into && !$this->isUninitialized($known)) {
            return $known;
        }
        $entity = $known ?? $this->reflection($map->className())->newInstanceWithoutConstructor();
        if (null === $known) {
            $this->identityMap->add($map->root, $id, $entity);
        }
        $ghost = null === $into && $this->isUninitialized($entity);
        $this->fill($map, $entity, $row, $id);
        if ($ghost) {
            $this->reflection($entity::class)->markLazyObjectAsInitialized($entity);
        }

        return $entity;
    }

    /** @param array<string, array<string, mixed>> $row */
    private function fill(EntityMap $map, object $entity, array $row, mixed $id): void
    {
        $class = $map->className();
        foreach ($map->entity->fields as $property => $field) {
            $value = $this->casts->toPhp($field->type, $row[$map->tableOf($property)][$field->column] ?? null);
            if (null !== $field->enum && null !== $value) {
                // A PHP enumeration (the mapping's enumType), as Doctrine's EnumPropertyAccessor makes it: from() each.
                $value = \is_array($value) ? array_map(static fn (mixed $member): \BackedEnum => $member instanceof \BackedEnum ? $member : $field->enum::from($member), $value) : ($value instanceof \BackedEnum ? $value : $field->enum::from($value));
            }
            $this->property($field->declaredIn ?? $class, $property)->setRawValueWithoutLazyInitialization($entity, $value);
        }
        foreach ($map->entity->relations as $property => $relation) {
            $this->property($relation->declaredIn ?? $class, $property)->setRawValueWithoutLazyInitialization($entity, $this->association($map, $relation, $row, $id));
        }
    }

    /** @param array<string, array<string, mixed>> $row */
    private function association(EntityMap $map, Relation $relation, array $row, mixed $id): mixed
    {
        $target = $this->mapping->of($relation->target);
        if ($relation->isToOne() && $relation->owning) {
            $key = $row[$map->tableOf($relation->property)][$map->joinColumn($relation)] ?? null;
            if (null === $key) {
                return null;
            }
            $key = $this->casts->toPhp($target->identifier[0]->type, $key);

            return $this->identityMap->get($target->root, $key)
                ?? ($target->hasSubclasses() ? $this->load($target, $key) : $this->ghost($target, $key));
        }
        if ($relation->isToOne()) {
            // The inverse side of a one-to-one: its row says whether there is one, read at once.
            return $this->select($relation->target, fn (Builder $query) => $query->where($target->column((string) $relation->mappedBy), '=', $this->ownerKey($map, $id)))[0] ?? null;
        }

        return new LazyCollection(fn (): array => $this->collection($map, $relation, $target, $id));
    }

    /** @return array<array-key, object> */
    private function collection(EntityMap $map, Relation $relation, EntityMap $target, mixed $id): array
    {
        $owner = $this->ownerKey($map, $id);
        $entities = $this->select($target->className(), function (Builder $query) use ($map, $relation, $target, $owner): void {
            if (Relation::ONE_TO_MANY === $relation->kind) {
                $query->where($target->column((string) $relation->mappedBy), '=', $owner);
            } else {
                [$joinTable, $ownerColumn, $targetColumn] = $this->joinTable($map, $relation, $target);
                $query->join($joinTable.' as j', 'j.'.$targetColumn, '=', $target->idColumns()[0])->where('j.'.$ownerColumn, '=', $owner);
            }
            foreach ($relation->orderBy as $property => $direction) {
                $query->orderBy($target->column($property), strtolower((string) $direction) === 'desc' ? 'desc' : 'asc');
            }
        });
        if (null === $relation->indexBy) {
            return $entities;
        }
        $indexed = [];
        foreach ($entities as $entity) {
            $field = $this->mapping->of($entity::class)->entity->fields[$relation->indexBy] ?? null;
            $key = null === $field ? null : $this->property($field->declaredIn ?? $entity::class, $field->property)->getRawValue($entity);
            $indexed[$key instanceof \BackedEnum ? $key->value : (string) $key] = $entity;
        }

        return $indexed;
    }

    /**
     * The join table of a many-to-many association, from the owning side's declaration: [table, the column of the
     * owner's identifier, the column of the target's].
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function joinTable(EntityMap $map, Relation $relation, EntityMap $target): array
    {
        if ($relation->owning) {
            $joinTable = $relation->joinTable ?? throw new \LogicException(\sprintf('%s::$%s has no join table.', $map->className(), $relation->property));

            return [$joinTable->name, $joinTable->joinColumns[0]->name, $joinTable->inverseJoinColumns[0]->name];
        }
        $owning = $target->entity->relations[(string) $relation->mappedBy] ?? throw new \LogicException(\sprintf('%s::$%s is mapped by %s::$%s, which is no association.', $map->className(), $relation->property, $target->className(), (string) $relation->mappedBy));
        $joinTable = $owning->joinTable ?? throw new \LogicException(\sprintf('%s::$%s has no join table.', $target->className(), $owning->property));

        return [$joinTable->name, $joinTable->inverseJoinColumns[0]->name, $joinTable->joinColumns[0]->name];
    }

    private function ghost(EntityMap $map, mixed $id): object
    {
        $ghost = $this->reflection($map->className())->newLazyGhost(function (object $ghost) use ($map, $id): void {
            if (null === $this->load($map, $id, $ghost)) {
                throw EntityNotFound::of($map->className(), $id);
            }
        }, \ReflectionClass::SKIP_INITIALIZATION_ON_SERIALIZE);
        foreach ($map->identifier as $field) {
            $this->property($field->declaredIn ?? $map->className(), $field->property)->setRawValueWithoutLazyInitialization($ghost, \is_array($id) ? $id[$field->property] : $id);
        }
        $this->identityMap->add($map->root, $id, $ghost);

        return $ghost;
    }

    /** The owner's identifier as its column holds it: what an association's foreign key is compared with. */
    private function ownerKey(EntityMap $map, mixed $id): mixed
    {
        if (1 !== \count($map->identifier)) {
            throw new \LogicException(\sprintf('%s has a composite identifier: its associations are not read by omnibridge/eloquent yet.', $map->className()));
        }

        return $this->casts->toDatabase($map->identifier[0]->type, \is_array($id) ? reset($id) : $id);
    }

    /** @param array<string, array<string, mixed>> $row */
    private function rowId(EntityMap $map, array $row): mixed
    {
        $values = [];
        foreach ($map->identifier as $field) {
            $values[$field->property] = $this->casts->toPhp($field->type, $row[$map->tables[0]][$field->column] ?? null);
        }

        return 1 === \count($values) ? reset($values) : $values;
    }

    private function normaliseId(EntityMap $map, mixed $id): mixed
    {
        if (\is_object($id) && $this->mapping->has($id::class)) {
            $id = $this->identifier($id);
        }
        if (1 === \count($map->identifier)) {
            $id = \is_array($id) ? reset($id) : $id;

            return $this->casts->toPhp($map->identifier[0]->type, $this->casts->toDatabase($map->identifier[0]->type, $id));
        }

        return $id;
    }

    /**
     * The select of some tables: each column under an alias ("t0.slug as c0_3"), with the table and column it reads.
     *
     * @param list<string> $tables
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function selection(string $key, array $tables, string $prefix): array
    {
        if (isset($this->selections[$key])) {
            return $this->selections[$key];
        }
        $selection = [];
        foreach (array_values($tables) as $index => $table) {
            foreach (array_values($this->mapping->description->table($table)->columns) as $position => $column) {
                $selection[] = [$prefix.$index.'.'.$column->name.' as c'.$index.'_'.$position, $table, $column->name];
            }
        }

        return $this->selections[$key] = $selection;
    }

    /**
     * @param array<string, mixed>                              $record
     * @param list<array{0: string, 1: string, 2: string}> $selection
     *
     * @return array<string, array<string, mixed>>
     */
    private static function unpack(array $record, array $selection): array
    {
        $row = [];
        foreach ($selection as [$expression, $table, $column]) {
            $row[$table][$column] = $record[substr($expression, strrpos($expression, ' as ') + 4)] ?? null;
        }

        return $row;
    }

    private function reflection(string $class): \ReflectionClass
    {
        static $reflections = [];

        return $reflections[$class] ??= new \ReflectionClass($class);
    }

    private function property(string $class, string $property): \ReflectionProperty
    {
        return $this->properties[$class][$property] ??= new \ReflectionProperty($class, $property);
    }
}
