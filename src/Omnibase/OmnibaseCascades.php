<?php

namespace Omnibridge\Eloquent\Omnibase;

use Base\Database\Attribute\Cascade;
use Base\Database\Attribute\OrphanRemoval;

/**
 * omnibase's own cascade declarations on Eloquent (plan "omnibase agnostic", step F7c): #[Cascade(value: [...])] and
 * #[OrphanRemoval] on an association's property, or on its class with the property named (`column`), which on Doctrine
 * rewrite the association's mapping when its metadata is loaded (their loadClassMetadata()). Read here from the
 * attributes' arguments, nothing instantiated: what Cascades::extend() takes.
 */
final class OmnibaseCascades
{
    /** @return \Closure(string, string, list<string>, bool): array{0: list<string>, 1: bool} */
    public static function resolver(): \Closure
    {
        return static function (string $class, string $property, array $cascade, bool $orphanRemoval): array {
            // The parents' first, the class's own last: what the class says prevails.
            $classes = [];
            for ($current = $class; false !== $current && class_exists($current); $current = get_parent_class($current)) {
                array_unshift($classes, $current);
            }
            foreach ($classes as $current) {
                $reflection = new \ReflectionClass($current);
                $attributes = $reflection->hasProperty($property) && $reflection->getProperty($property)->getDeclaringClass()->getName() === $current
                    ? array_map(static fn (\ReflectionAttribute $attribute): array => [$attribute, true], $reflection->getProperty($property)->getAttributes())
                    : [];
                foreach ($reflection->getAttributes() as $attribute) {
                    $attributes[] = [$attribute, false];
                }
                foreach ($attributes as [$attribute, $onProperty]) {
                    if (!\in_array($attribute->getName(), [Cascade::class, OrphanRemoval::class], true)) {
                        continue;
                    }
                    $arguments = $attribute->getArguments();
                    $column = $arguments['column'] ?? $arguments[0] ?? '';
                    if (!$onProperty && $column !== $property) {
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
}
