<?php

namespace Omnibridge\Eloquent;

/**
 * One object per row: the entities a store read (or referenced), by their root class and identifier, so
 * that a row read twice - by id, through an association, in a finder's result - is the same object, as
 * Doctrine's unit of work keeps it.
 */
final class IdentityMap
{
    /** @var array<string, array<string, object>> root class => identifier key => entity */
    private array $entities = [];

    /** @var \WeakMap<object, array{0: string, 1: string}> entity => [root class, identifier key] */
    private \WeakMap $keys;

    public function __construct()
    {
        $this->keys = new \WeakMap();
    }

    /** The key of an identifier: its value, or its values joined for a composite one. */
    public static function key(mixed $id): string
    {
        if (\is_array($id)) {
            return implode("\0", array_map(static fn (mixed $value): string => self::key($value), $id));
        }

        return match (true) {
            $id instanceof \DateTimeInterface => $id->format('Y-m-d H:i:s.u'),
            $id instanceof \BackedEnum => (string) $id->value,
            \is_bool($id) => $id ? '1' : '0',
            default => (string) $id,
        };
    }

    public function get(string $root, mixed $id): ?object
    {
        return $this->entities[$root][self::key($id)] ?? null;
    }

    public function add(string $root, mixed $id, object $entity): void
    {
        $key = self::key($id);
        $this->entities[$root][$key] = $entity;
        $this->keys[$entity] = [$root, $key];
    }

    public function contains(object $entity): bool
    {
        return isset($this->keys[$entity]);
    }

    public function remove(object $entity): void
    {
        if (!isset($this->keys[$entity])) {
            return;
        }
        [$root, $key] = $this->keys[$entity];
        unset($this->entities[$root][$key], $this->keys[$entity]);
    }

    public function count(): int
    {
        return array_sum(array_map('count', $this->entities));
    }

    public function clear(): void
    {
        $this->entities = [];
        $this->keys = new \WeakMap();
    }
}
