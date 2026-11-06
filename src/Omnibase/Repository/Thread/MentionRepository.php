<?php

namespace Omnibridge\Eloquent\Omnibase\Repository\Thread;

use Base\Entity\Thread;
use Base\Entity\Thread\Mention;
use Base\Repository\Thread\Mentions;
use Omnibridge\Eloquent\Omnibase\EloquentEntities;

/**
 * omnibridge/doctrine's Thread\MentionRepository on Eloquent (plan "omnibase agnostic", step F7b): the same finders, compiled by
 * EloquentFinder.
 *
 * @extends EloquentEntities<Mention>
 */
class MentionRepository extends EloquentEntities implements Mentions
{
    public function byThread(Thread $thread): array
    {
        return $this->cacheByThread($thread)->getResult();
    }
}
