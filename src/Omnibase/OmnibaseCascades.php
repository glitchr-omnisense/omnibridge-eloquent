<?php

namespace Omnibridge\Eloquent\Omnibase;

use Base\Database\Attribute\Alias;
use Base\Database\Attribute\Cascade;
use Base\Database\Attribute\OrphanRemoval;
use Base\Database\Entity\Extension\TranslatableInterface;
use Base\Database\Entity\Extension\TranslationInterface;

/**
 * omnibase's own cascade declarations on Eloquent (plan "omnibase agnostic", step F7c): #[Cascade(value: [...])] and
 * #[OrphanRemoval] on an association's property, or on its class with the property named (`column`), which on Doctrine
 * rewrite the association's mapping when its metadata is loaded (their loadClassMetadata()). Read here from the
 * attributes' arguments, nothing instantiated: what Cascades::extend() takes. And the translations' association, which
 * omnibridge/doctrine's IntlSubscriber maps (step F7c).
 */
final class OmnibaseCascades
{
    /** @return \Closure(string, string, list<string>, bool): array{0: list<string>, 1: bool} */
    public static function resolver(): \Closure
    {
        return static function (string $class, string $property, array $cascade, bool $orphanRemoval): array {
            // A translatable's translations and a translation's translatable, which omnibridge/doctrine's IntlSubscriber
            // maps when Doctrine loads them (cascade persist, refresh, remove and orphan removal; persist and refresh).
            if ('translations' === $property && is_subclass_of($class, TranslatableInterface::class)) {
                [$cascade, $orphanRemoval] = [['persist', 'refresh', 'remove'], true];
            } elseif ('translatable' === $property && is_subclass_of($class, TranslationInterface::class)) {
                [$cascade, $orphanRemoval] = [['persist', 'refresh'], false];
            }

            // The parents' first, the class's own last: what the class says prevails.
            $classes = [];
            for ($current = $class; false !== $current && class_exists($current); $current = get_parent_class($current)) {
                array_unshift($classes, $current);
            }
            foreach ($classes as $current) {
                $reflection = new \ReflectionClass($current);
                // The property itself and, as omnibase's loadClassMetadata() resolves them, the properties of the class
                // that are its #[Alias] (Gallery::$photos, #[Alias(column: "children")], #[OrphanRemoval]: the
                // children's orphans removed - step F7d-2).
                $aliases = self::aliasesOf($reflection, $property);
                $attributes = [];
                foreach ([$property, ...$aliases] as $name) {
                    if ($reflection->hasProperty($name) && $reflection->getProperty($name)->getDeclaringClass()->getName() === $current) {
                        foreach ($reflection->getProperty($name)->getAttributes() as $attribute) {
                            $attributes[] = [$attribute, true];
                        }
                    }
                }
                foreach ($reflection->getAttributes() as $attribute) {
                    $attributes[] = [$attribute, false];
                }
                foreach ($attributes as [$attribute, $onProperty]) {
                    if (!\in_array($attribute->getName(), [Cascade::class, OrphanRemoval::class], true)) {
                        continue;
                    }
                    $arguments = $attribute->getArguments();
                    $column = $arguments['column'] ?? $arguments[0] ?? '';
                    if (!$onProperty && $column !== $property && !\in_array($column, $aliases, true)) {
                        continue;
                    }
                    $value = $arguments['value'] ?? $arguments[1] ?? null;
                    if (Cascade::class === $attribute->getName()) {
                        $cascade = array_values(array_map('strtolower', (array) ($value ?? [])));
                    } else {
                        $orphanRemoval = null === $value ? true : (bool) $value;
                    }
                }
            }

            return [$cascade, $orphanRemoval];
        };
    }

    /**
     * The properties a class declares as another's #[Alias] (Base\Database\Attribute\Alias, its `column`).
     *
     * @return list<string>
     */
    private static function aliasesOf(\ReflectionClass $reflection, string $property): array
    {
        $aliases = [];
        foreach ($reflection->getProperties() as $candidate) {
            if ($candidate->getDeclaringClass()->getName() !== $reflection->getName()) {
                continue;
            }
            foreach ($candidate->getAttributes(Alias::class) as $alias) {
                $arguments = $alias->getArguments();
                if (($arguments['column'] ?? $arguments[0] ?? null) === $property) {
                    $aliases[] = $candidate->getName();
                }
            }
        }

        return $aliases;
    }
}
