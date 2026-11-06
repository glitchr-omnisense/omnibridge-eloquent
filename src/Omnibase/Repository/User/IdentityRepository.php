<?php

namespace Omnibridge\Eloquent\Omnibase\Repository\User;

use Base\Entity\User\Identity;
use Base\Repository\User\Identities;
use Omnibridge\Eloquent\Omnibase\EloquentEntities;

/**
 * omnibridge/doctrine's User\IdentityRepository on Eloquent (plan "omnibase agnostic", step F7b): the same finders, compiled by
 * EloquentFinder.
 *
 * @extends EloquentEntities<Identity>
 */
class IdentityRepository extends EloquentEntities implements Identities
{
    public function findOneBySubject(string $provider, string $subject): ?Identity
    {
        return $this->findOneBy(['provider' => $provider, 'subject' => $subject]);
    }

    public function findOfUser(object $user): array
    {
        return $this->findBy(['user' => $user], ['provider' => 'ASC']);
    }
}
