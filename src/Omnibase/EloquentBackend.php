<?php

namespace Omnibridge\Eloquent\Omnibase;

use Base\Database\Scope\TrashScope;
use Base\Database\Scope\VaultScope;
use Illuminate\Database\Query\Builder;
use Omnibridge\Eloquent\EloquentUnitOfWork;
use Omnibridge\Eloquent\EntityMap;
use Omnibridge\Persistence\Backend;
use Omnibridge\Persistence\Scope;
use Omnibridge\Persistence\UnitOfWork;

/**
 * omnisense/omnibridge's Backend on omnibridge/eloquent (plan "omnibase agnostic", step F7b): the name
 * "eloquent", what it can do (lazy associations: ghosts and lazy collections; no second-level or result cache;
 * transactions with the writes, at F7c), its unit of work, and omnibase's scopes - the trash and the vault -
 * added to every query of the store while they are enabled, as Doctrine's TrashFilter and VaultFilter are: a
 * scope's criteria (Scope::criteria()) on the entity's columns, `deletedAt IS NULL`, `vault IS NULL OR vault = :environment`
 * - in the class's own query, and in the ON of a join of it (the finder's), as Doctrine puts its filters there.
 */
final class EloquentBackend implements Backend
{
    /** @var array<string, Scope> */
    private array $scopes;

    /** @param list<Scope> $scopes more scopes than omnibase's trash and vault */
    public function __construct(private readonly EloquentUnitOfWork $unitOfWork, array $scopes = [])
    {
        $this->scopes = [];
        foreach ([new TrashScope(), new VaultScope(), ...$scopes] as $scope) {
            $this->scopes[$scope->name()] = $scope;
        }
    }

    public function name(): string
    {
        return 'eloquent';
    }

    public function supports(string $feature): bool
    {
        return self::LAZY_ASSOCIATIONS === $feature;
    }

    public function unitOfWork(): UnitOfWork
    {
        return $this->unitOfWork;
    }

    public function enableScope(string $name, array $parameters = []): void
    {
        $scope = $this->scopes[$name] ?? throw new \InvalidArgumentException(sprintf('No scope "%s" on Eloquent (%s).', $name, implode(', ', array_keys($this->scopes))));
        $this->unitOfWork->store()->addCondition('scope:'.$name, static function (EntityMap $map, Builder $query, string $prefix = 't') use ($scope, $parameters): void {
            if (!$scope->appliesTo($map->className())) {
                return;
            }
            foreach ($scope->criteria($map->className(), $parameters) as $field => $value) {
                $column = $map->column($field, $prefix);
                if (null === $value) {
                    $query->whereNull($column);
                } elseif (\is_array($value)) {
                    $values = array_values(array_filter($value, static fn (mixed $member): bool => null !== $member));
                    $nullable = \count($values) !== \count($value);
                    $query->where(static function (Builder $query) use ($column, $values, $nullable): void {
                        $query->whereIn($column, $values);
                        if ($nullable) {
                            $query->orWhereNull($column);
                        }
                    });
                } else {
                    $query->where($column, '=', $value);
                }
            }
        });
    }

    public function disableScope(string $name): void
    {
        $this->unitOfWork->store()->removeCondition('scope:'.$name);
    }

    public function isScopeEnabled(string $name): bool
    {
        return $this->unitOfWork->store()->hasCondition('scope:'.$name);
    }
}
