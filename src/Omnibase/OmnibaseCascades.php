<?php

namespace Omnibridge\Eloquent\Omnibase;

use Base\Database\Attribute\Cascade;
use Base\Database\Entity\Extension\TranslatableInterface;
use Base\Database\Entity\Extension\TranslationInterface;

/**
 * omnibase's own cascade declarations on Eloquent (plan "omnibase agnostic", step F7c): #[Cascade(value: [...])] and
 * #[OrphanRemoval] on an association's property, or on its class with the property named (`column`), which on Doctrine
 * rewrite the association's mapping when its metadata is loaded (their loadClassMetadata()). Read by the core's
 * Cascade::resolve(): what Cascades::extend() takes. And the translations' association, which
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

            // #[Cascade] and #[OrphanRemoval]: the core's one reading (Doctrine's mapping takes the same).
            return Cascade::resolve($class, $property, $cascade, $orphanRemoval);
        };
    }
}
