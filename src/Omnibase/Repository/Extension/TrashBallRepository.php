<?php

namespace Omnibridge\Eloquent\Omnibase\Repository\Extension;

use Base\Entity\Extension\TrashBall;
use Base\Repository\Extension\TrashBalls;
use Omnibridge\Eloquent\Omnibase\EloquentEntities;

/**
 * omnibridge/doctrine's Extension\TrashBallRepository on Eloquent (plan "omnibase agnostic", step F7b): the same finders, compiled by
 * EloquentFinder.
 *
 * @extends EloquentEntities<TrashBall>
 */
class TrashBallRepository extends EloquentEntities implements TrashBalls
{
    public function contents(?string $entityClass = null, ?int $limit = null, int $offset = 0): array
    {
        return $this->findBy(null === $entityClass ? [] : ['entityClass' => $entityClass], ['id' => 'DESC'], $limit, $offset);
    }

    public function countContents(?string $entityClass = null): int
    {
        return $this->count(null === $entityClass ? [] : ['entityClass' => $entityClass]);
    }

    public function expired(?\DateTimeInterface $now = null, ?int $limit = null): array
    {
        // permanentAfter <= now, which no row without a date meets.
        return $this->findByPermanentAfterLowerEqualTo($now ?? new \DateTime(), [], ['permanentAfter' => 'ASC'], $limit)->getResult();
    }

    public function forEntity(string $entityClass, int $entityId): ?TrashBall
    {
        return $this->findOneBy(['entityClass' => $entityClass, 'entityId' => $entityId], ['id' => 'DESC']);
    }
}
