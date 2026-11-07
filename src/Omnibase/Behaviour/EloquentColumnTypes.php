<?php

namespace Omnibridge\Eloquent\Omnibase\Behaviour;

use Base\Database\Backend\ColumnTypes;
use Omnibridge\Eloquent\EloquentUnitOfWork;

/**
 * ColumnTypes on omnibridge/eloquent (plan "omnibase agnostic", step F7c): a field holds a PHP array when its type is
 * `json` or a set enumeration (the store's casts know the core's and the application's, OmnibaseCasts) - what
 * DoctrineColumnTypes answers from Doctrine's types.
 */
final class EloquentColumnTypes implements ColumnTypes
{
    public function __construct(private readonly EloquentUnitOfWork $unitOfWork)
    {
    }

    public function isArray(string $type): bool
    {
        if ('json' === $type) {
            return true;
        }

        return isset($this->unitOfWork->store()->casts()->columnTypes()?->sets[$type]);
    }
}
