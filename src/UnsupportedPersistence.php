<?php

namespace Omnibridge\Eloquent;

/**
 * What omnibridge/eloquent's unit of work does not do, refused aloud rather than done wrong (omnisense/omnibase's
 * plan "omnibase agnostic", step F7c): an identifier the database cannot give it (a sequence, a custom generator),
 * a composite identifier or join column, a unit of work closed by a failed flush.
 */
final class UnsupportedPersistence extends \LogicException
{
    public static function generator(string $class, string $strategy): self
    {
        return new self(\sprintf('%s\'s identifier is generated with the "%s" strategy: omnibridge/eloquent writes the database\'s own (AUTO, IDENTITY) or one the application gives (NONE), no other.', $class, $strategy));
    }

    public static function composite(string $class, string $what): self
    {
        return new self(\sprintf('%s has a composite %s: omnibridge/eloquent does not write it.', $class, $what));
    }

    public static function closed(): self
    {
        return new self('The unit of work is closed: a flush failed and was rolled back, as Doctrine closes its entity manager; make another one.');
    }

    public static function operation(string $operation, string $why): self
    {
        return new self(\sprintf('omnibridge/eloquent does not %s: %s.', $operation, $why));
    }
}
