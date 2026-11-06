<?php

namespace Omnibridge\Eloquent;

/**
 * A write the unit of work refuses because the entities asked for it wrongly, where Doctrine's unit of work
 * refuses it too (its ORMInvalidArgumentException): a new entity reached through an association that does not
 * cascade the persist, a cycle of mandatory foreign keys, an entity of no described class.
 */
final class InvalidPersistence extends \InvalidArgumentException
{
    public static function notCascaded(string $class, string $property, object $entity): self
    {
        return new self(\sprintf('A new entity was found through the relationship %s::$%s that was not configured to cascade persist operations for entity: %s. Persist it explicitly, or declare cascade: ["persist"] on the association.', $class, $property, self::describe($entity)));
    }

    public static function cycle(array $entities): self
    {
        return new self(\sprintf('A cycle of mandatory foreign keys between new entities: %s. One of them must be nullable to insert them.', implode(' -> ', array_map(self::describe(...), $entities))));
    }

    public static function notEntity(object $entity): self
    {
        return new self(\sprintf('%s is no entity of the description.', $entity::class));
    }

    public static function notManaged(object $entity, string $operation): self
    {
        return new self(\sprintf('The %s to %s is not managed by this unit of work.', self::describe($entity), $operation));
    }

    public static function invalidAssociation(string $class, string $property, mixed $value): self
    {
        return new self(\sprintf('%s::$%s holds a %s, which is no entity of its target.', $class, $property, get_debug_type($value)));
    }

    private static function describe(object $entity): string
    {
        return $entity::class.'@'.spl_object_id($entity);
    }
}
