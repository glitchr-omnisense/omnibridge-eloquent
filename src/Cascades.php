<?php

namespace Omnibridge\Eloquent;

use Omnibridge\Schema\Declaration\AttributeReader;
use Omnibridge\Schema\Declaration\Declarations;
use Omnibridge\Schema\Relation;

/**
 * What an association carries along, as its declaration says (the mapping attribute's `cascade` and
 * `orphanRemoval`, read by omnisense/omnibridge's declaration reader, no ORM loaded): which operations of the
 * unit of work go on to the associated entities (persist, remove; refresh, detach and merge are Doctrine's own),
 * and whether an entity taken out of the association is deleted (orphan removal).
 *
 * The description of the tables does not carry them (they change no table); an application whose own
 * attributes add some (omnibase's #[Cascade] and #[OrphanRemoval]) gives a resolver (extend()).
 */
final class Cascades
{
    /** @var array<string, array{0: list<string>, 1: bool}> class::property => [cascade, orphanRemoval] */
    private array $resolved = [];

    /** @var list<\Closure(string, string, list<string>, bool): array{0: list<string>, 1: bool}> */
    private array $resolvers = [];

    public function __construct(private readonly Declarations $declarations = new AttributeReader())
    {
    }

    /**
     * A resolver of an application's own declarations: given the entity's class, the association's property, and what
     * its mapping attribute says, it answers [cascade, orphanRemoval].
     *
     * @param \Closure(string, string, list<string>, bool): array{0: list<string>, 1: bool} $resolver
     */
    public function extend(\Closure $resolver): void
    {
        $this->resolvers[] = $resolver;
        $this->resolved = [];
    }

    /** Whether the operation ("persist", "remove", "refresh"...) goes on to the association's entities. */
    public function cascades(string $class, Relation $relation, string $operation): bool
    {
        $cascade = $this->resolve($class, $relation)[0];

        return \in_array($operation, $cascade, true) || \in_array('all', $cascade, true);
    }

    /** Whether an entity taken out of the association (or replaced in it) is deleted. */
    public function orphanRemoval(string $class, Relation $relation): bool
    {
        return $this->resolve($class, $relation)[1];
    }

    /** @return array{0: list<string>, 1: bool} */
    private function resolve(string $class, Relation $relation): array
    {
        $key = $class.'::'.$relation->property;
        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        $name = match ($relation->kind) {
            Relation::ONE_TO_ONE => 'OneToOne',
            Relation::MANY_TO_ONE => 'ManyToOne',
            Relation::ONE_TO_MANY => 'OneToMany',
            Relation::MANY_TO_MANY => 'ManyToMany',
        };
        $cascade = [];
        $orphanRemoval = false;
        for ($current = $class; false !== $current && class_exists($current); $current = get_parent_class($current)) {
            $attribute = $this->declarations->read($current)?->property($relation->property)?->attribute($name);
            if (null !== $attribute) {
                $cascade = array_values(array_map('strtolower', (array) ($attribute->get('cascade') ?? [])));
                $orphanRemoval = (bool) $attribute->get('orphanRemoval');
                break;
            }
        }
        foreach ($this->resolvers as $resolver) {
            [$cascade, $orphanRemoval] = $resolver($class, $relation->property, $cascade, $orphanRemoval);
        }

        return $this->resolved[$key] = [$cascade, $orphanRemoval];
    }
}
