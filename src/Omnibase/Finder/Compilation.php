<?php

namespace Omnibridge\Eloquent\Omnibase\Finder;

use Illuminate\Database\Query\Builder;
use Omnibridge\Eloquent\EntityMap;

/**
 * One finder query being compiled (EloquentFinder): the class's query from the store, the associations joined
 * (once each, as ServiceEntityParser's join list keeps them), the condition the criteria make - folded as
 * Doctrine's QueryBuilder folds andWhere()/orWhere(), each call wrapping what came before -, the order, the
 * grouping and the selected values besides the entity's columns, in the order a mixed result lists them.
 *
 * A condition is a closure adding itself to a builder with a boolean: `fn (Builder $query, string $boolean)`.
 *
 * @internal
 */
final class Compilation
{
    /** @var array<string, array{0: string, 1: EntityMap}> an association => [the prefix its tables are aliased under, its target's map] */
    public array $joins = [];

    /** @var (\Closure(Builder, string): void)|null */
    private ?\Closure $condition = null;

    /** @var list<array{0: string, 1: list<mixed>}> the ORDER BY terms: [SQL with its direction, bindings] */
    private array $orders = [];

    /** @var list<string> the GROUP BY terms (SQL) */
    private array $groups = [];

    /** @var list<array{0: string, 1: string, 2: list<mixed>}> [result key, SQL, bindings] */
    private array $scalars = [];

    /** @var array<string, string> a selected expression's name => its SQL, for a criterion or an order naming it */
    public array $named = [];

    /** The key the entity has in a mixed result's row: "entity" for a grouped query, 0 beside other values, none alone. */
    public int|string|null $entityKey = null;

    public function __construct(public readonly Builder $query)
    {
    }

    /** The andWhere() of Doctrine's QueryBuilder: what came before AND this. */
    public function andWhere(\Closure $condition): void
    {
        $this->condition = null === $this->condition ? $condition : Conditions::all([$this->condition, $condition]);
    }

    /** The orWhere() of Doctrine's QueryBuilder: what came before OR this. */
    public function orWhere(\Closure $condition): void
    {
        $this->condition = null === $this->condition ? $condition : Conditions::any([$this->condition, $condition]);
    }

    /** @param list<mixed> $bindings */
    public function addOrder(string $sql, array $bindings = []): void
    {
        $this->orders[] = [$sql, $bindings];
    }

    /** No order: ORDER BY RAND() (Doctrine's orderBy(), which replaces the terms before it), a count. */
    public function resetOrders(): void
    {
        $this->orders = [];
    }

    public function addGroup(string $sql): void
    {
        $this->groups[] = $sql;
    }

    /** @param list<mixed> $bindings */
    public function addScalar(string|int $key, string $sql, array $bindings = []): void
    {
        $this->scalars[] = [(string) $key, $sql, $bindings];
    }

    /**
     * The builder with everything added: the condition as one nested group (the store's conditions, the scopes, stay
     * beside it, as Doctrine's SQL filters stay beside its WHERE), the selected values under aliases of their own, the
     * order, the grouping.
     *
     * @return array<string, string> a mixed result's key => the column its value comes back under
     */
    public function finish(): array
    {
        $query = $this->query;
        if (null !== $this->condition) {
            $condition = $this->condition;
            $query->where(static function (Builder $nested) use ($condition): void {
                $condition($nested, 'and');
            });
        }
        $columns = [];
        foreach ($this->scalars as $index => [$key, $sql, $bindings]) {
            $alias = 'f_'.$index;
            $query->selectRaw($sql.' as '.$query->getGrammar()->wrap($alias), $bindings);
            $columns[$key] = $alias;
        }
        if ([] !== $this->groups) {
            $query->groupByRaw(implode(', ', $this->groups));
        }
        foreach ($this->orders as [$sql, $bindings]) {
            $query->orderByRaw($sql, $bindings);
        }

        return $columns;
    }
}
