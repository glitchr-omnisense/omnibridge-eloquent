<?php

namespace Omnibridge\Eloquent\Omnibase\Repository\User;

use Base\Entity\User\Complaint;
use Base\Repository\User\Complaints;
use Omnibridge\Eloquent\Omnibase\EloquentEntities;

/**
 * omnibridge/doctrine's User\ComplaintRepository on Eloquent (plan "omnibase agnostic", step F7b): the same finders, compiled by
 * EloquentFinder.
 *
 * @extends EloquentEntities<Complaint>
 */
class ComplaintRepository extends EloquentEntities implements Complaints
{
    public function newest(?string $status = null, ?string $source = null, int $limit = 200): array
    {
        $criteria = array_filter(['status' => $status, 'source' => $source], static fn ($value) => null !== $value);

        return $this->findBy($criteria, ['createdAt' => 'DESC'], $limit);
    }
}
