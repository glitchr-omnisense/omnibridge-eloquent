<?php

namespace Omnibridge\Eloquent\Tests\Support;

use Omnibridge\Schema\Description;
use Omnibridge\Schema\Relation;

/**
 * An entity as plain data, whatever read it: its class, each mapped field's value (a date as its class, its
 * time to the microsecond and its zone; an enumeration as its value), each association as the class and
 * identifier of what it holds - a to-many association as the list of them, in order when it declares one
 * (else sorted: an unordered collection's order is the database's), keyed when it is indexed. Two backends read
 * the same row when they give the same data.
 */
final class Normaliser
{
    /** @var array<string, \ReflectionProperty> */
    private array $properties = [];

    public function __construct(private readonly Description $description)
    {
    }

    /** @return array<string, mixed> */
    public function entity(object $entity): array
    {
        $class = $entity::class;
        $described = $this->description->entities[$class] ?? $this->description->entities[(new \ReflectionClass($class))->getName()]
            ?? throw new \OutOfBoundsException($class.' is not described.');
        $data = ['class' => $class, 'fields' => [], 'relations' => []];
        foreach ($described->fields as $property => $field) {
            $data['fields'][$property] = $this->value($this->property($field->declaredIn ?? $class, $property)->getValue($entity));
        }
        foreach ($described->relations as $property => $relation) {
            $value = $this->property($relation->declaredIn ?? $class, $property)->getValue($entity);
            if ($relation->isToOne()) {
                $data['relations'][$property] = null === $value ? null : $this->reference($value);
                continue;
            }
            $members = [];
            foreach ($value ?? [] as $key => $member) {
                $members[$key] = $this->reference($member);
            }
            if (null === $relation->indexBy) {
                $members = array_values($members);
                if ([] === $relation->orderBy) {
                    usort($members, static fn (array $a, array $b): int => [$a[0], (string) $a[1]] <=> [$b[0], (string) $b[1]]);
                }
            } else {
                ksort($members);
            }
            $data['relations'][$property] = $members;
        }

        return $data;
    }

    /** @return array{0: string, 1: mixed} an associated entity's class and identifier, read without loading it */
    public function reference(object $entity): array
    {
        $class = $entity::class;
        $described = $this->description->entities[$class] ?? $this->description->entities[(new \ReflectionClass($class))->getName()]
            ?? throw new \OutOfBoundsException($class.' is not described.');
        $id = [];
        foreach ($described->identifier as $property) {
            $field = $described->fields[$property];
            $id[] = $this->value($this->property($field->declaredIn ?? $class, $property)->getRawValue($entity));
        }

        return [$class, 1 === \count($id) ? $id[0] : $id];
    }

    private function value(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \DateTimeInterface => ['@date', $value::class, $value->format('Y-m-d H:i:s.u'), $value->getTimezone()->getName()],
            $value instanceof \UnitEnum => ['@enum', $value::class, $value instanceof \BackedEnum ? $value->value : $value->name],
            \is_array($value) => array_map($this->value(...), $value),
            \is_resource($value) => stream_get_contents($value),
            \is_object($value) => ['@object', $value::class, json_decode(json_encode($value, \JSON_PARTIAL_OUTPUT_ON_ERROR) ?: 'null', true)],
            default => $value,
        };
    }

    private function property(string $class, string $property): \ReflectionProperty
    {
        return $this->properties[$class.'::'.$property] ??= new \ReflectionProperty($class, $property);
    }
}
