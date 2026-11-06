<?php

namespace Omnibridge\Eloquent;

use Omnibridge\Schema\Description;
use Omnibridge\Schema\Entity;
use Omnibridge\Schema\Field;
use Omnibridge\Schema\Inheritance;
use Omnibridge\Schema\Relation;

/**
 * Where an entity's values are, read from its description: the tables of its
 * class, each field's and each owning association's table and column, its
 * identifier, and how a row says which class it is.
 *
 * - No inheritance: the entity's table.
 * - Joined inheritance: the tables from the root's to the class's, each row
 *   joined on the identifier; a field or an association sits in the table of the
 *   class that maps it (Field::$inheritedFrom), the root's discriminator column
 *   says the row's class (Inheritance::$map).
 * - Single-table inheritance: the root's table for every class, the
 *   discriminator naming the row's class; a class below the root reads only
 *   the rows of its own value and its subclasses' (discriminatorValues()).
 *
 * The tables of a query are aliased t0 (the root's), t1... in that order
 * (alias(), column()), the extra tables of a subclass after them; another
 * prefix than "t" names them where the class is joined to another's query
 * (the finder's joins: j1_0, j1_1...).
 */
final class EntityMap
{
    /** @var list<string> the tables from the root's to the class's */
    public readonly array $tables;

    /** @var list<Field> the identifier's fields */
    public readonly array $identifier;

    public readonly string $root;

    public readonly ?Inheritance $inheritance;

    /** @var array<string, string> a field's or owning association's property => its table */
    private array $tableOf = [];

    public function __construct(
        public readonly Description $description,
        public readonly Entity $entity,
    ) {
        $this->root = $entity->root ?? $entity->class;
        $rootEntity = $description->entities[$this->root] ?? $entity;
        $this->inheritance = $rootEntity->inheritance;

        if (Inheritance::JOINED === $this->inheritance?->type) {
            $chain = [];
            for ($class = $entity->class; null !== $class; $class = $description->entities[$class]->parent ?? null) {
                $current = $description->entities[$class] ?? null;
                if (null === $current) {
                    break;
                }
                if (Entity::ENTITY === $current->kind && null !== $current->table && !\in_array($current->table, $chain, true)) {
                    array_unshift($chain, $current->table);
                }
                if ($class === $this->root) {
                    break;
                }
            }
            $this->tables = $chain;
        } else {
            $this->tables = [(string) ($rootEntity->table ?? $entity->table)];
        }

        foreach ($entity->fields as $property => $field) {
            $this->tableOf[$property] = $this->tableOfClass($field->inheritedFrom);
        }
        foreach ($entity->relations as $property => $relation) {
            if ($relation->isToOne() && $relation->owning) {
                $this->tableOf[$property] = $this->tableOfClass($relation->inheritedFrom);
            }
        }

        $identifier = [];
        foreach ($entity->identifier as $property) {
            if (!isset($entity->fields[$property])) {
                throw new \LogicException(\sprintf('%s is identified by its association "%s": an identifier through an association is not read by omnibridge/eloquent yet.', $entity->class, $property));
            }
            $identifier[] = $entity->fields[$property];
        }
        $this->identifier = $identifier;
    }

    public function className(): string
    {
        return $this->entity->class;
    }

    /** The table alias of one of the class's tables: t0 for the root's (j1_0 under the prefix "j1_"). */
    public function alias(string $table, string $prefix = 't'): string
    {
        $index = array_search($table, $this->tables, true);
        if (false === $index) {
            throw new \OutOfBoundsException(\sprintf('The table %s is none of %s\'s (%s).', $table, $this->entity->class, implode(', ', $this->tables)));
        }

        return $prefix.$index;
    }

    /** The table holding a field or an owning to-one association's column. */
    public function tableOf(string $property): string
    {
        return $this->tableOf[$property] ?? throw new \OutOfBoundsException(\sprintf('%s::$%s has no column of its own.', $this->entity->class, $property));
    }

    /** "alias.column" of a field, or of an owning to-one association's (first) join column. */
    public function column(string $property, string $prefix = 't'): string
    {
        $table = $this->tableOf($property);
        if (isset($this->entity->fields[$property])) {
            return $this->alias($table, $prefix).'.'.$this->entity->fields[$property]->column;
        }

        return $this->alias($table, $prefix).'.'.$this->joinColumn($this->entity->relations[$property]);
    }

    /** Whether a field or an owning to-one association has a column of its own (column() answers for it). */
    public function hasColumn(string $property): bool
    {
        return isset($this->tableOf[$property]);
    }

    /** The column an owning to-one association keeps its target's identifier in. */
    public function joinColumn(Relation $relation): string
    {
        if (\count($relation->joinColumns) > 1) {
            throw new \LogicException(\sprintf('%s::$%s joins on %d columns: a composite join is not read by omnibridge/eloquent yet.', $this->entity->class, $relation->property, \count($relation->joinColumns)));
        }

        return ($relation->joinColumns[0] ?? throw new \LogicException(\sprintf('%s::$%s has no join column.', $this->entity->class, $relation->property)))->name;
    }

    /** "t0.id": the identifier's column in the root's table (one column; composite identifiers are read by row). */
    public function idColumns(string $prefix = 't'): array
    {
        return array_map(fn (Field $field): string => $prefix.'0.'.$field->column, $this->identifier);
    }

    /** The discriminator's column in the root's table, null without inheritance. */
    public function discriminator(): ?string
    {
        return $this->inheritance?->column->name;
    }

    /**
     * The discriminator values of the class and its subclasses: the rows a single-table query of a class below
     * the root reads. Null when every row of the table is the class's (no inheritance, the root, joined inheritance).
     *
     * @return list<string>|null
     */
    public function discriminatorValues(): ?array
    {
        if (Inheritance::SINGLE_TABLE !== $this->inheritance?->type || $this->entity->class === $this->root) {
            return null;
        }
        $classes = array_merge([$this->entity->class], $this->entity->subclasses);
        $values = [];
        foreach ($this->inheritance->map as $value => $class) {
            if (\in_array($class, $classes, true)) {
                $values[] = (string) $value;
            }
        }

        return $values;
    }

    /** The class a row is, by its discriminator (the class itself without inheritance). */
    public function classOf(array $row): string
    {
        if (null === $this->inheritance) {
            return $this->entity->class;
        }
        $value = $row[$this->tables[0]][$this->inheritance->column->name] ?? null;

        return $this->inheritance->map[(string) $value] ?? throw new \UnexpectedValueException(\sprintf('The discriminator "%s" of a %s row names no class (%s).', (string) $value, $this->root, implode(', ', array_keys($this->inheritance->map))));
    }

    /** Whether rows of this class may be of a subclass: an association to it cannot be a ghost of the class itself. */
    public function hasSubclasses(): bool
    {
        return [] !== $this->entity->subclasses;
    }

    private function tableOfClass(?string $inheritedFrom): string
    {
        if (Inheritance::JOINED !== $this->inheritance?->type || null === $inheritedFrom) {
            return $this->tables[\count($this->tables) - 1];
        }
        $table = $this->description->entities[$inheritedFrom]->table ?? null;

        return null !== $table && \in_array($table, $this->tables, true) ? $table : $this->tables[\count($this->tables) - 1];
    }
}
