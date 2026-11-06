<?php

namespace Omnibridge\Eloquent;

use Omnibridge\Schema\Description;
use Omnibridge\Schema\Entity;

/**
 * The tables of every entity a description holds (EntityMap per class), the
 * class asked by any of its names: an entity's own, or a class alias of it
 * (omnibase's App\Entity\X for Base\Entity\X where the application has none).
 */
final class Mapping
{
    /** @var array<string, EntityMap> */
    private array $maps = [];

    public function __construct(public readonly Description $description)
    {
    }

    public function of(string $class): EntityMap
    {
        if (isset($this->maps[$class])) {
            return $this->maps[$class];
        }
        $entity = $this->description->entities[$class] ?? null;
        if (null === $entity && class_exists($class)) {
            $entity = $this->description->entities[(new \ReflectionClass($class))->getName()] ?? null;
        }
        if (null === $entity || Entity::ENTITY !== $entity->kind) {
            throw new \OutOfBoundsException(\sprintf('%s is no entity of the description.', $class));
        }

        return $this->maps[$class] = $this->maps[$entity->class] ??= new EntityMap($this->description, $entity);
    }

    public function has(string $class): bool
    {
        try {
            $this->of($class);

            return true;
        } catch (\OutOfBoundsException) {
            return false;
        }
    }

    /**
     * The entities the description maps (no mapped superclass, no embeddable).
     *
     * @return list<string>
     */
    public function classes(): array
    {
        return array_values(array_map(static fn (Entity $entity): string => $entity->class, array_filter($this->description->entities, static fn (Entity $entity): bool => Entity::ENTITY === $entity->kind)));
    }
}
