<?php

namespace Omnibridge\Eloquent\Tests\Support\Site;

use App\Entity\Newsletter\Subscriber;
use App\Repository\Newsletter\Subscribers;
use Omnibridge\Eloquent\Omnibase\EloquentBackend;
use Omnibridge\Eloquent\Omnibase\EloquentEntities;

/**
 * lesringostars' App\Repository\Newsletter\Subscribers on Eloquent, for the flows compared at step F7c (third part,
 * tests/Site/FlowParityTest): each method the finder its Doctrine repository (SubscriberRepository) wraps, the trash
 * scope off where that one turns Doctrine's trash_filter off. An application's own Eloquent repositories are step
 * F7f's; this one stays in the tests, loaded in lesringostars' suite only.
 *
 * @extends EloquentEntities<Subscriber>
 */
final class EloquentSubscribers extends EloquentEntities implements Subscribers
{
    public function oneByEmail(?string $email): ?Subscriber
    {
        return $this->findOneBy(['email' => $email]);
    }

    public function cachedOneByEmail(?string $email): ?Subscriber
    {
        return $this->oneByEmail($email);
    }

    public function findOneByEmailIncludingTrashed(?string $email): ?Subscriber
    {
        if (!$email) {
            return null;
        }
        $backend = new EloquentBackend($this->unitOfWork());
        $enabled = $backend->isScopeEnabled('trash');
        $enabled && $backend->disableScope('trash');
        try {
            return $this->findOneBy(['email' => $email]);
        } finally {
            $enabled && $backend->enableScope('trash');
        }
    }
}
