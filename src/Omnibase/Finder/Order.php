<?php

namespace Omnibridge\Eloquent\Omnibase\Finder;

/**
 * An order a criterion makes instead of a condition: ClosestTo and FarestTo, `ABS(x - ?)` ascending or descending,
 * appended to the call's order as ServiceEntityParser appends it.
 *
 * @internal
 */
final class Order
{
    /** @param list<mixed> $bindings */
    public function __construct(
        public readonly string $sql,
        public readonly array $bindings,
        public readonly string $direction,
    ) {
    }
}
