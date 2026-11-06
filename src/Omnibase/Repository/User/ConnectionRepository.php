<?php

namespace Omnibridge\Eloquent\Omnibase\Repository\User;

use Base\Entity\User;
use Base\Entity\User\Connection;
use Base\Repository\User\Connections;
use Omnibridge\Eloquent\Omnibase\EloquentEntities;

/**
 * omnibridge/doctrine's User\ConnectionRepository on Eloquent (plan "omnibase agnostic", step F7b): the same finders, compiled by
 * EloquentFinder.
 *
 * @extends EloquentEntities<Connection>
 */
class ConnectionRepository extends EloquentEntities implements Connections
{
    public function oneByUniqidAndUser(string $uniqid, User $user): ?Connection
    {
        return $this->findOneByUniqidAndUser($uniqid, ['user' => $user]);
    }

    public function countFor(User $user): int
    {
        return $this->count(['user' => $user]);
    }
}
