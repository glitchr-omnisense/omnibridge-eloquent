<?php

namespace Omnibridge\Eloquent\Omnibase\Behaviour;

use Base\Attributes\AbstractAttribute;
use Base\Database\Attribute\Backend\CacheBackend;
use Base\Database\Attribute\Cache;
use Omnibridge\Eloquent\EloquentUnitOfWork;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * #[Cache]'s part on omnibridge/eloquent (plan "omnibase agnostic", step F7c): the store keeps no second-level cache,
 * so nothing is evicted. What DoctrineCacheBackend does whether a cache is configured or not stays: for the entity
 * itself, each association whose target is cached is read through its getter first (a lazy one is loaded), so that
 * the entity is in the same state on both backends when its row is written.
 */
final class EloquentCacheBackend implements CacheBackend
{
    public function __construct(private readonly EloquentUnitOfWork $unitOfWork)
    {
    }

    public function evict(Cache $cache, object $entity, ?string $property = null): void
    {
        if (null !== $property) {
            return;
        }

        $mapping = $this->unitOfWork->store()->mapping();
        if (!$mapping->has($entity::class)) {
            return;
        }
        $propertyAccessor = PropertyAccess::createPropertyAccessor();
        foreach ($mapping->of($entity::class)->entity->relations as $association => $relation) {
            if (empty(AbstractAttribute::getAttributeReader()->getClassAttributes($relation->target, Cache::class))) {
                continue;
            }
            $propertyAccessor->getValue($entity, $association); // read, as Doctrine's backend reads it
        }
    }
}
