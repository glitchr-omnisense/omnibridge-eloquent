<?php

namespace Omnibridge\Eloquent\Omnibase\Behaviour;

use Base\Database\Backend\EntityCache;
use Omnibridge\Eloquent\EloquentUnitOfWork;

/** EntityCache on omnibridge/eloquent (plan "omnibase agnostic", step F7c): the store keeps no second-level cache. */
final class EloquentEntityCache implements EntityCache
{
    public function __construct(private readonly EloquentUnitOfWork $unitOfWork)
    {
    }

    public function enabled(): bool
    {
        return false;
    }

    public function evict(string $class, mixed $id): void
    {
    }

    public function evictClass(string $class): void
    {
    }
}
