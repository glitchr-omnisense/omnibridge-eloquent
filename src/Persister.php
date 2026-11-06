<?php

namespace Omnibridge\Eloquent;

use Illuminate\Database\Query\Builder;
use Omnibridge\Schema\Relation;

/**
 * The statements of a flush, as Doctrine's entity and collection persisters write them, through Eloquent's query
 * builder: each column a change set gives, by the table that holds it (EntityMap).
 *
 * - insert(): the root's table first with the discriminator (an inheritance), the identifier the database gives
 *   read back (AUTO, IDENTITY), then each table the class adds (joined inheritance) with that identifier. A
 *   to-one association to an entity whose row is not written yet is written NULL, and returned as an extra update
 *   (a nullable foreign key in a cycle, as Doctrine does);
 * - update(): the changed columns, table by table, by identifier;
 * - delete(): the join tables' rows of its many-to-many associations (but those the database cascades), then the
 *   row of the root's table - the tables a joined class adds go with it by their foreign key, as Doctrine leaves
 *   them to the database;
 * - the rows of a many-to-many association's join table: added, taken out, all of an owner's taken out.
 */
final class Persister
{
    public function __construct(private readonly EntityStore $store)
    {
    }

    /**
     * Inserts the entity's rows; returns the identifier the database gave (null when the application gave it) and
     * the to-one associations written NULL because their target has no row yet.
     *
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet property => [before, after]
     * @param \Closure(object): bool                   $pending   whether an entity's row is still to be written
     *
     * @return array{0: mixed, 1: array<string, array{0: mixed, 1: mixed}>} [the generated identifier, the extra update]
     */
    public function insert(object $entity, array $changeSet, \Closure $pending): array
    {
        $map = $this->store->mapping()->of($entity::class);
        $extra = [];
        $data = $this->columns($map, $entity, $changeSet, $pending, $extra);
        if (null !== $map->inheritance) {
            $value = array_search($map->className(), $map->inheritance->map, true);
            if (false === $value) {
                throw new \LogicException(\sprintf('%s has no discriminator value in %s\'s map.', $map->className(), $map->root));
            }
            $data[$map->tables[0]][$map->inheritance->column->name] = (string) $value;
        }

        $generated = null;
        $id = null;
        $root = $map->tables[0];
        if (self::generated($map)) {
            $field = $map->identifier[0];
            $raw = $this->table($root)->insertGetId($data[$root] ?? []);
            $generated = $this->store->casts()->toPhp($field->type, $raw);
            $id = [$field->column => $this->store->casts()->toDatabase($field->type, $generated)];
        } else {
            $id = $this->idColumns($map, $entity);
            $this->table($root)->insert(($data[$root] ?? []) + $id);
        }
        foreach (\array_slice($map->tables, 1) as $table) {
            $this->table($table)->insert(($data[$table] ?? []) + $id);
        }

        return [$generated, $extra];
    }

    /**
     * Updates the changed columns of the entity's rows (an extra update too: its change set is the columns written
     * NULL at its insert).
     *
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet
     */
    public function update(object $entity, array $changeSet): void
    {
        $map = $this->store->mapping()->of($entity::class);
        $extra = [];
        $data = $this->columns($map, $entity, $changeSet, static fn (): bool => false, $extra);
        $id = $this->idColumns($map, $entity);
        foreach ($map->tables as $table) {
            if ([] !== ($data[$table] ?? [])) {
                $this->table($table)->where($id)->update($data[$table]);
            }
        }
    }

    /** Deletes the entity's row (and the join tables' rows of its many-to-many associations, unless cascaded). */
    public function delete(object $entity): void
    {
        $map = $this->store->mapping()->of($entity::class);
        $id = $this->idColumns($map, $entity);
        $key = reset($id);
        foreach ($map->entity->relations as $relation) {
            if (Relation::MANY_TO_MANY !== $relation->kind) {
                continue;
            }
            [$table, $ownerColumn, $targetColumn, $cascaded, $selfReferential] = $this->joinTable($map, $relation);
            if ($cascaded) {
                continue;
            }
            $this->table($table)->where($ownerColumn, '=', $key)->delete();
            if ($selfReferential) {
                $this->table($table)->where($targetColumn, '=', $key)->delete();
            }
        }
        $this->table($map->tables[0])->where($id)->delete();
    }

    /**
     * The join table of a many-to-many association, from its owning side's declaration: [table, the owner's column,
     * the target's column, whether the database deletes its rows with an entity (Doctrine's isOnDeleteCascade, of
     * the owning side only), whether the association is to its own class].
     *
     * @return array{0: string, 1: string, 2: string, 3: bool, 4: bool}
     */
    public function joinTable(EntityMap $map, Relation $relation): array
    {
        $target = $this->store->mapping()->of($relation->target);
        $owning = $relation->owning ? $relation : ($target->entity->relations[(string) $relation->mappedBy] ?? throw new \LogicException(\sprintf('%s::$%s is mapped by %s::$%s, which is no association.', $map->className(), $relation->property, $target->className(), (string) $relation->mappedBy)));
        $joinTable = $owning->joinTable ?? throw new \LogicException(\sprintf('%s::$%s has no join table.', $map->className(), $owning->property));
        if (1 !== \count($joinTable->joinColumns) || 1 !== \count($joinTable->inverseJoinColumns)) {
            throw UnsupportedPersistence::composite($map->className(), 'join table on '.$relation->property);
        }
        $cascaded = false;
        if ($relation->owning) {
            foreach ([...$joinTable->joinColumns, ...$joinTable->inverseJoinColumns] as $column) {
                $cascaded = $cascaded || 'cascade' === strtolower((string) $column->onDelete);
            }
        }
        [$ownerColumn, $targetColumn] = $relation->owning
            ? [$joinTable->joinColumns[0]->name, $joinTable->inverseJoinColumns[0]->name]
            : [$joinTable->inverseJoinColumns[0]->name, $joinTable->joinColumns[0]->name];

        return [$joinTable->name, $ownerColumn, $targetColumn, $cascaded, $relation->target === $map->className() || $relation->target === $map->root];
    }

    /**
     * Rows of an owning many-to-many association: those of the targets taken out deleted, those of the targets added
     * inserted (Doctrine's ManyToManyPersister::update()), or every row of the owner deleted first ($all: the
     * collection was replaced).
     *
     * @param list<object> $removed
     * @param list<object> $added
     */
    public function writeJoinRows(object $owner, Relation $relation, array $removed, array $added, bool $all = false): void
    {
        $map = $this->store->mapping()->of($owner::class);
        [$table, $ownerColumn, $targetColumn] = $this->joinTable($map, $relation);
        $ownerKey = $this->key($owner);
        if ($all) {
            $this->table($table)->where($ownerColumn, '=', $ownerKey)->delete();
        }
        foreach ($removed as $target) {
            $this->table($table)->where($ownerColumn, '=', $ownerKey)->where($targetColumn, '=', $this->key($target))->delete();
        }
        foreach ($added as $target) {
            $this->table($table)->insert([$ownerColumn => $ownerKey, $targetColumn => $this->key($target)]);
        }
    }

    /** Whether the class's identifier is the database's (AUTO, IDENTITY), read back after the insert. */
    public static function generated(EntityMap $map): bool
    {
        if (1 !== \count($map->identifier)) {
            return false;
        }

        return \in_array(strtolower((string) $map->identifier[0]->generated), ['auto', 'identity'], true);
    }

    /**
     * The columns of a change set by table: a field's value as its column keeps it, a to-one association's target's
     * identifier (NULL, and an extra update, for a target whose row is not written yet).
     *
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet
     * @param array<string, array{0: mixed, 1: mixed}> $extra
     *
     * @return array<string, array<string, mixed>>
     */
    private function columns(EntityMap $map, object $entity, array $changeSet, \Closure $pending, array &$extra): array
    {
        $data = [];
        $casts = $this->store->casts();
        foreach ($changeSet as $property => [, $value]) {
            if (isset($map->entity->fields[$property])) {
                $field = $map->entity->fields[$property];
                $data[$map->tableOf($property)][$field->column] = $casts->toDatabase($field->type, $value);
                continue;
            }
            $relation = $map->entity->relations[$property] ?? null;
            if (null === $relation || !$relation->isToOne() || !$relation->owning) {
                continue;
            }
            if (\count($relation->joinColumns) > 1) {
                throw UnsupportedPersistence::composite($map->className(), 'join column on '.$property);
            }
            if (null !== $value && $pending($value) && !($value === $entity && !self::generated($map))) {
                $extra[$property] = [null, $value];
                $value = null;
            }
            $data[$map->tableOf($property)][$map->joinColumn($relation)] = null === $value ? null : $this->key($value);
        }

        return $data;
    }

    /** The identifier of an entity as its column keeps it (what a foreign key holds). */
    private function key(object $entity): mixed
    {
        $map = $this->store->mapping()->of($entity::class);
        if (1 !== \count($map->identifier)) {
            throw UnsupportedPersistence::composite($map->className(), 'identifier');
        }
        $id = $this->store->identifier($entity);

        return $this->store->casts()->toDatabase($map->identifier[0]->type, $id);
    }

    /** @return array<string, mixed> column => value of the entity's identifier */
    private function idColumns(EntityMap $map, object $entity): array
    {
        $id = $this->store->identifier($entity);
        $columns = [];
        foreach ($map->identifier as $field) {
            $value = \is_array($id) ? ($id[$field->property] ?? null) : $id;
            $columns[$field->column] = $this->store->casts()->toDatabase($field->type, $value);
        }

        return $columns;
    }

    private function table(string $table): Builder
    {
        return $this->store->connection()->table($table);
    }
}
