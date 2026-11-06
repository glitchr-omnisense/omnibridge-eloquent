<?php

namespace Omnibridge\Eloquent\Omnibase\Repository\Thread;

use Base\Entity\Thread;
use Base\Entity\Thread\Like;
use Base\Entity\User;
use Base\Repository\Thread\Likes;
use Omnibridge\Eloquent\Omnibase\EloquentEntities;

/**
 * omnibridge/doctrine's Thread\LikeRepository on Eloquent (plan "omnibase agnostic", step F7b): the same finders, compiled by
 * EloquentFinder.
 *
 * @extends EloquentEntities<Like>
 */
class LikeRepository extends EloquentEntities implements Likes
{
    public function oneByThreadAndUser(Thread $thread, ?User $user): ?Like
    {
        return $this->findOneByThreadAndUser($thread, $user);
    }
}
