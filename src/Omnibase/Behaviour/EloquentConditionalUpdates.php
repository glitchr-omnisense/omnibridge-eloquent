<?php

namespace Omnibridge\Eloquent\Omnibase\Behaviour;

use Base\Database\Backend\ConditionalUpdates;
use Omnibridge\Eloquent\EloquentUnitOfWork;

/**
 * ConditionalUpdates on omnibridge/eloquent (plan "omnibase agnostic", step F7c): the field's column set to a new value
 * where it still holds the old one, in one statement (DoctrineConditionalUpdates' DQL UPDATE); true when the row was.
 */
final class EloquentConditionalUpdates implements ConditionalUpdates
{
    public function __construct(private readonly EloquentUnitOfWork $unitOfWork)
    {
    }

    public function compareAndSet(object $entity, string $field, mixed $from, mixed $to): bool
    {
        if (1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $field)) {
            throw new \InvalidArgumentException(sprintf('"%s" is no field name.', $field));
        }
        $store = $this->unitOfWork->store();
        $map = $store->mapping()->of($entity::class);
        $declared = $map->entity->fields[$field] ?? throw new \InvalidArgumentException(sprintf('%s has no field "%s".', $entity::class, $field));
        $casts = $store->casts();
        $identifier = $map->identifier[0];
        $table = $map->tableOf($field);

        return 1 === $store->connection()->table($table)
            ->where($identifier->column, '=', $casts->toDatabase($identifier->type, $this->unitOfWork->identifier($entity)))
            ->where($declared->column, '=', $casts->toDatabase($declared->type, $from))
            ->update([$declared->column => $casts->toDatabase($declared->type, $to)]);
    }
}
