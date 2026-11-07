<?php

namespace Omnibridge\Eloquent\Omnibase\Repository\Layout;

use Base\Entity\Layout\Redirection;
use Base\Repository\Layout\Redirections;
use Omnibridge\Eloquent\Omnibase\EloquentEntities;

/**
 * omnibridge/doctrine's Layout\RedirectionRepository on Eloquent (plan "omnibase agnostic", step F7b): the same finders, compiled by
 * EloquentFinder; hit(), declared #[BackendOnly] on the interface (no finder), one UPDATE on Eloquent's connection (F7f).
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

    /**
     * omnibridge/doctrine's bulk DQL UPDATE as one statement on the row (step F7f): hits = hits + 1 in the database,
     * atomic under concurrent requests, and the moment of the hit; the entity in memory is left as it was read, as a
     * DQL UPDATE leaves it.
     */
    public function hit(Redirection $redirection): void
    {
        $map = $this->entityMap();
        $casts = $this->store->casts();
        $identifier = $map->identifier[0];
        $hits = $map->entity->fields['hits'];
        $lastHitAt = $map->entity->fields['lastHitAt'];
        $connection = $this->store->connection();

        $connection->table($map->tableOf('hits'))
            ->where($identifier->column, '=', $casts->toDatabase($identifier->type, $redirection->getId()))
            ->update([
                $hits->column => $connection->raw($connection->getQueryGrammar()->wrap($hits->column).' + 1'),
                $lastHitAt->column => $casts->toDatabase($lastHitAt->type, new \DateTimeImmutable('now', new \DateTimeZone('UTC'))),
            ]);
    }
}
