<?php

namespace Omnibridge\Eloquent\Omnibase\Repository\User;

use Base\Entity\User\Token;
use Base\Repository\User\Tokens;
use Omnibridge\Eloquent\Omnibase\EloquentEntities;

/**
 * omnibridge/doctrine's User\TokenRepository on Eloquent (plan "omnibase agnostic", step F7b): the same finders, compiled by
 * EloquentFinder.
 *
 * @extends EloquentEntities<Token>
 */
class TokenRepository extends EloquentEntities implements Tokens
{
    public function oneByValueAndName(?string $value, string $name): ?Token
    {
        return $this->findOneByValueAndName($value, $name);
    }

    public function oneByValue(?string $value): ?Token
    {
        return $this->findOneByValue($value);
    }

    public function oneSigningInByNameAndValue(string $name, ?string $value): ?Token
    {
        return $this->findOneByNameAndValueAndIsLogginable($name, $value, true);
    }

    public function oneSigningInByValue(?string $value): ?Token
    {
        return $this->findOneByValueAndIsLogginable($value);
    }
}
