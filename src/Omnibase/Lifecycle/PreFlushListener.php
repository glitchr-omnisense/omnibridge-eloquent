<?php

namespace Omnibridge\Eloquent\Omnibase\Lifecycle;

use Omnibridge\Persistence\Lifecycle;

/**
 * A service's preFlush() called as each flush begins, on omnibridge/eloquent (plan "omnibase agnostic", step F7c):
 * omnisense/omnibase's UserSubscriber, whose preFlush() takes no Doctrine argument (the tokens it revokes).
 */
final class PreFlushListener
{
    /** @param \Closure(): object $service a service with a preFlush() taking no argument */
    public function __construct(private readonly \Closure $service)
    {
    }

    public function __invoke(object $event): void
    {
        if ($event instanceof Lifecycle\PreFlush) {
            ($this->service)()->preFlush();
        }
    }
}
