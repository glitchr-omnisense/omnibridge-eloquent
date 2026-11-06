<?php

namespace Omnibridge\Eloquent\Omnibase\Finder;

use Base\Database\Mapping\EntityFields;
use Base\Database\Repository\Finder\Subject;
use Omnibridge\Eloquent\EntityMap;
use Omnibridge\Schema\Relation;

/**
 * What omnibase's finder language asks of an entity (Base\Database\Repository\Finder\Subject), answered from its
 * description (plan "omnibase agnostic", step F7b): its class, its fields and associations, the names a criterion
 * may give them - its aliases (#[Alias]), a field by its column, as omnibridge/doctrine's ClassMetadataManipulator::
 * getFieldNames() reads them from Doctrine's metadata -, and a Query-by-Example's criteria (the set fields and to-one
 * associations of the object given, as ServiceEntityRepository::criteriaFromSelect() reads them).
 */
final class EntitySubject implements Subject
{
    /** @var array<string, string>|null a name => the property it names */
    private ?array $names = null;

    public function __construct(private readonly EntityMap $map)
    {
    }

    public function map(): EntityMap
    {
        return $this->map;
    }

    public function getClassName(): string
    {
        return $this->map->className();
    }

    public function getFieldNames(): array
    {
        return array_values($this->names());
    }

    public function hasField(string $name): bool
    {
        return $this->isField($name) || $this->isAssociation($name);
    }

    public function isField(string $name): bool
    {
        return isset($this->map->entity->fields[$name]);
    }

    public function isAssociation(string $name): bool
    {
        return isset($this->map->entity->relations[$name]);
    }

    public function relation(string $name): ?Relation
    {
        return $this->map->entity->relations[$name] ?? null;
    }

    /** The type of a field, through a to-one association for a path ("translatable.state"); null for anything else. */
    public function typeOf(string $path, \Closure $mapOf): ?string
    {
        $map = $this->map;
        $segments = explode('.', $path);
        while (\count($segments) > 1) {
            $relation = $map->entity->relations[array_shift($segments)] ?? null;
            if (null === $relation) {
                return null;
            }
            $map = $mapOf($relation->target);
        }

        return $map->entity->fields[$segments[0]]->type ?? null;
    }

    /**
     * The property a name gives (an alias, a column, a field, an association), the name itself when it is none -
     * ServiceEntityParser::getAlias().
     */
    public function alias(?string $name): ?string
    {
        if (null === $name) {
            return null;
        }

        return $this->names()[$name] ?? $name;
    }

    public function criteriaFromSelect(object $select): array
    {
        $criteria = [];
        foreach ((new \ReflectionClass($select))->getProperties() as $property) {
            $name = $property->getName();
            $relation = $this->map->entity->relations[$name] ?? null;
            if (!$this->isField($name) && (null === $relation || !$relation->isToOne())) {
                continue; // not a queryable mapped field / to-one association
            }
            if (!$property->isInitialized($select)) {
                continue; // never set on this select
            }
            $value = $property->getValue($select);
            if (null === $value) {
                continue; // a select cannot express "IS NULL"
            }
            $criteria[$name] = $value;
        }

        return $criteria;
    }

    /**
     * Every name of the entity's properties, name => property: its aliases, its fields by their columns, its
     * associations - in that order, a later one winning, as ClassMetadataManipulator::getFieldNames() merges them.
     *
     * @return array<string, string>
     */
    private function names(): array
    {
        if (null !== $this->names) {
            return $this->names;
        }
        $columns = [];
        foreach ($this->map->entity->fields as $property => $field) {
            $columns[$field->column] = $property;
        }
        $associations = array_keys($this->map->entity->relations);

        return $this->names = array_merge(EntityFields::aliases($this->map->className()), $columns, array_combine($associations, $associations));
    }
}
