<?php

namespace Omnibridge\Eloquent\Omnibase\Behaviour;

use Base\Database\Attribute\Backend\OrderColumnBackend;
use Base\Database\Common\Collections\OrderedArrayCollection;
use Omnibridge\Eloquent\EloquentUnitOfWork;
use Omnibridge\Eloquent\LazyCollection;

/**
 * #[OrderColumn]'s part on omnibridge/eloquent (plan "omnibase agnostic", step F7c): a to-many association the store
 * reads lazily is ordered by the stored positions without being read now - its elements are held, once it is read,
 * in omnibase's OrderedArrayCollection, which sorts itself on its first use (what DoctrineOrderColumnBackend does
 * inside Doctrine's PersistentCollection); one read already is wrapped the same way, its entries kept.
 */
final class EloquentOrderColumnBackend implements OrderColumnBackend
{
    public function __construct(private readonly EloquentUnitOfWork $unitOfWork)
    {
    }

    public function orderLazily(object $entity, mixed $collection, array $positions): bool
    {
        if (!$collection instanceof LazyCollection) {
            return false;
        }

        $collection->wrap(static fn (array $elements) => new OrderedArrayCollection($elements, $positions));

        return true;
    }
}
