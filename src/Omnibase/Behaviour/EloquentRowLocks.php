<?php

namespace Omnibridge\Eloquent\Omnibase\Behaviour;

use Base\Database\Backend\RowLocks;
use Omnibridge\Eloquent\EloquentUnitOfWork;

/**
 * RowLocks on omnibridge/eloquent (plan "omnibase agnostic", step F7c): the work run in a transaction holding the
 * entity's row (SELECT ... FOR UPDATE, as Doctrine's PESSIMISTIC_WRITE lock reads it; SQLite has no row lock, its
 * transaction is the lock).
 */
final class EloquentRowLocks implements RowLocks
{
    public function __construct(private readonly EloquentUnitOfWork $unitOfWork)
    {
    }

    public function locked(object $entity, callable $work): mixed
    {
        $store = $this->unitOfWork->store();
        $connection = $store->connection();
        $map = $store->mapping()->of($entity::class);
        $connection->beginTransaction();
        try {
            $id = $store->identifier($entity);
            $query = $connection->table($map->tables[0]);
            foreach ($map->identifier as $field) {
                $query->where($field->column, '=', $store->casts()->toDatabase($field->type, \is_array($id) ? $id[$field->property] : $id));
            }
            $query->lockForUpdate()->selectRaw('1')->get();
            $result = $work();
            $connection->commit();

            return $result;
        } catch (\Throwable $e) {
            $connection->rollBack();

            throw $e;
        }
    }
}
