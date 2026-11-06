<?php

namespace Omnibridge\Eloquent\Omnibase\Repository\User;

use Base\Entity\User\PushSubscription;
use Base\Repository\User\PushSubscriptions;
use Omnibridge\Eloquent\Omnibase\EloquentEntities;

/**
 * omnibridge/doctrine's User\PushSubscriptionRepository on Eloquent (plan "omnibase agnostic", step F7b): the same finders, compiled by
 * EloquentFinder.
 *
 * @extends EloquentEntities<PushSubscription>
 */
class PushSubscriptionRepository extends EloquentEntities implements PushSubscriptions
{
    public function countDistinctUsers(): int
    {
        return (int) $this->distinctCountForUserBy();
    }
}
