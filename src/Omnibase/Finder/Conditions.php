<?php

namespace Omnibridge\Eloquent\Omnibase\Finder;

use Illuminate\Database\Query\Builder;

/**
 * The conditions a finder compiles to, as closures adding themselves to an Illuminate builder with a boolean
 * ("and", "or"): raw SQL with its bindings (so that `x = NULL` stays `x = NULL`, as Doctrine's `e.x = :x` with a
 * null parameter is, where Illuminate's where() would write IS NULL), and their AND, OR and NOT - Doctrine's
 * Expr\Andx, Orx and not().
 *
 * @internal
 */
final class Conditions
{
    /** @param list<mixed> $bindings */
    public static function raw(string $sql, array $bindings = []): \Closure
    {
        return static function (Builder $query, string $boolean) use ($sql, $bindings): void {
            $query->whereRaw($sql, $bindings, $boolean);
        };
    }

    /** @param list<\Closure> $parts */
    public static function all(array $parts): \Closure
    {
        return self::nested($parts, 'and');
    }

    /** @param list<\Closure> $parts */
    public static function any(array $parts): \Closure
    {
        return self::nested($parts, 'or');
    }

    public static function not(\Closure $part): \Closure
    {
        return static function (Builder $query, string $boolean) use ($part): void {
            $query->whereNot(static function (Builder $nested) use ($part): void {
                $part($nested, 'and');
            }, null, null, $boolean);
        };
    }

    /** @param list<\Closure> $parts */
    private static function nested(array $parts, string $join): \Closure
    {
        $parts = array_values($parts);
        if (1 === \count($parts)) {
            return $parts[0];
        }

        return static function (Builder $query, string $boolean) use ($parts, $join): void {
            $query->where(static function (Builder $nested) use ($parts, $join): void {
                foreach ($parts as $part) {
                    $part($nested, $join);
                }
            }, null, null, $boolean);
        };
    }
}
