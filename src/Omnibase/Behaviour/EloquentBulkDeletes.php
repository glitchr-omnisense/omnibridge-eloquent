<?php

namespace Omnibridge\Eloquent\Omnibase\Behaviour;

use Base\Database\Backend\BulkDeletes;
use Illuminate\Database\Query\Builder;
use Omnibridge\Eloquent\EloquentUnitOfWork;
use Omnibridge\Eloquent\EntityMap;
use Omnibridge\Schema\Entity;

/**
 * BulkDeletes on omnibridge/eloquent (plan "omnibase agnostic", step F7c): the rows of a class whose fields equal the
 * criteria deleted in statements, no entity read nor event dispatched - DoctrineBulkDeletes' DQL DELETE: the class's
 * rows only (a single-table class by its discriminator), the store's scopes applied (Doctrine's filters), and for a
 * joined hierarchy every table of it, the subclasses' first (Doctrine's multi-table delete). The number of rows.
 */
final class EloquentBulkDeletes implements BulkDeletes
{
    public function __construct(private readonly EloquentUnitOfWork $unitOfWork)
    {
    }

    public function deleteWhere(string $class, array $criteria): int
    {
        $store = $this->unitOfWork->store();
        $map = $store->mapping()->of($class);
        $casts = $store->casts();
        $query = $store->query($map);
        foreach ($criteria as $field => $value) {
            if (1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $field)) {
                throw new \InvalidArgumentException(sprintf('"%s" is no field name.', $field));
            }
            $declared = $map->entity->fields[$field] ?? throw new \InvalidArgumentException(sprintf('%s has no field "%s".', $class, $field));
            $query->where($map->column($field), '=', $casts->toDatabase($declared->type, $value));
        }

        $identifier = $map->identifier[0];
        $ids = array_values(array_unique(array_map(static fn (object $row): mixed => ((array) $row)['__id'], $query->select($map->column($identifier->property).' as __id')->get()->all())));
        if ([] === $ids) {
            return 0;
        }

        $tables = $this->tables($map);
        foreach (array_reverse($tables) as $table) {
            foreach (array_chunk($ids, 500) as $chunk) {
                $store->connection()->table($table)->whereIn($identifier->column, $chunk)->delete();
            }
        }

        return \count($ids);
    }

    /**
     * The rows of those identifiers deleted, in each table of the class (the subclasses' first): what a DQL
     * `DELETE FROM <class> e WHERE e.id IN (:ids)` deletes (VersionableSubscriber's trimming). The number of rows.
     *
     * @param list<mixed> $ids
     */
    public function deleteIds(string $class, array $ids): int
    {
        $store = $this->unitOfWork->store();
        $map = $store->mapping()->of($class);
        $identifier = $map->identifier[0];
        $ids = array_values(array_map(static fn (mixed $id): mixed => $store->casts()->toDatabase($identifier->type, $id), $ids));
        if ([] === $ids) {
            return 0;
        }
        $deleted = 0;
        foreach (array_reverse($this->tables($map)) as $index => $table) {
            foreach (array_chunk($ids, 500) as $chunk) {
                $count = $store->connection()->table($table)->whereIn($identifier->column, $chunk)->delete();
                $deleted = $table === $map->tables[0] ? $deleted + $count : $deleted;
            }
        }

        return $deleted;
    }

    /** @return list<string> the class's tables, the root's first, then each subclass's of a joined hierarchy */
    private function tables(EntityMap $map): array
    {
        $tables = $map->tables;
        if (\count($tables) < 2 && !$map->hasSubclasses()) {
            return $tables;
        }
        foreach ($map->description->entities as $entity) {
            if (Entity::ENTITY === $entity->kind && null !== $entity->table && is_a($entity->class, $map->className(), true) && !\in_array($entity->table, $tables, true) && ($entity->root ?? $entity->class) === $map->root) {
                $tables[] = $entity->table;
            }
        }

        return $map->inheritance?->type === \Omnibridge\Schema\Inheritance::JOINED ? $tables : [$tables[0]];
    }
}
