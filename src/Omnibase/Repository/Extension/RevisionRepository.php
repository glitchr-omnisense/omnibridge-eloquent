<?php

namespace Omnibridge\Eloquent\Omnibase\Repository\Extension;

use Base\Entity\Extension\Revision;
use Base\Repository\Extension\Revisions;
use Omnibridge\Eloquent\Omnibase\EloquentEntities;

/**
 * omnibridge/doctrine's Extension\RevisionRepository on Eloquent (plan "omnibase agnostic", step F7b): the same finders, compiled by
 * EloquentFinder.
 *
 * @extends EloquentEntities<Revision>
 */
class RevisionRepository extends EloquentEntities implements Revisions
{
    public function history(string $entityClass, int $entityId, ?int $limit = null): array
    {
        return $this->findBy(['entityClass' => $entityClass, 'entityId' => $entityId], ['id' => 'DESC'], $limit);
    }

    /** @return list<int> the revisions past the $keep newest, a thousand at most */
    public function obsoleteIds(string $entityClass, int $entityId, int $keep): array
    {
        if ($keep < 1) {
            return [];
        }
        $obsolete = $this->findBy(['entityClass' => $entityClass, 'entityId' => $entityId], ['id' => 'DESC'], 1000, $keep);

        return array_map(static fn (Revision $revision): int => (int) $revision->getId(), $obsolete);
    }
}
