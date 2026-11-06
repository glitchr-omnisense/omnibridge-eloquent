<?php

namespace Omnibridge\Eloquent\Omnibase\Repository\Layout;

use Base\Database\Repository\BackendOnly;
use Base\Entity\Layout\Redirection;
use Base\Repository\Layout\Redirections;
use Omnibridge\Eloquent\Omnibase\EloquentEntities;

/**
 * omnibridge/doctrine's Layout\RedirectionRepository on Eloquent (plan "omnibase agnostic", step F7b): the same finders, compiled by
 * EloquentFinder.
 *
 * @extends EloquentEntities<Redirection>
 */
class RedirectionRepository extends EloquentEntities implements Redirections
{
    public function forAddress(string $address): ?Redirection
    {
        $path = strtok($address, '?') ?: '/';
        $candidates = array_values(array_unique([$address, $path]));
        $exact = $this->findBy(['enabled' => true, 'source' => $candidates]);
        foreach ($candidates as $candidate) {
            foreach ($exact as $redirection) {
                if ($redirection->getSource() === $candidate) {
                    return $redirection;
                }
            }
        }
        // What the database found for the address, whatever the case (its collation): the same letters still win above.
        foreach ($candidates as $candidate) {
            foreach ($exact as $redirection) {
                if (mb_strtolower($redirection->getSource()) === mb_strtolower($candidate)) {
                    return $redirection;
                }
            }
        }
        $prefixes = $this->findByEnabledTrueAndSourceEndingWith('*')->getResult();
        usort($prefixes, static fn (Redirection $a, Redirection $b) => \strlen($b->getSource()) <=> \strlen($a->getSource()));
        foreach ($prefixes as $redirection) {
            if (null !== $redirection->resolve($address)) {
                return $redirection;
            }
        }

        return null;
    }

    /** A bulk UPDATE (hits = hits + 1, atomic): omnibridge/doctrine's only, refused aloud here. */
    public function hit(Redirection $redirection): void
    {
        BackendOnly::refuse(Redirections::class, __FUNCTION__, 'eloquent');
    }
}
