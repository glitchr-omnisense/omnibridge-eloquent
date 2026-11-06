<?php

namespace Omnibridge\Eloquent;

/**
 * What omnibridge/eloquent does not do (yet), refused aloud rather than done wrong: writing (persist, remove,
 * flush, transactions, change sets) comes with its unit of work, at omnisense/omnibase's plan "omnibase
 * agnostic", step F7c; until then the store reads only.
 */
final class UnsupportedPersistence extends \LogicException
{
    public static function writes(string $operation): self
    {
        return new self(\sprintf('omnibridge/eloquent reads only: %s() writes, which its unit of work does not do yet (plan "omnibase agnostic", step F7c).', $operation));
    }
}
