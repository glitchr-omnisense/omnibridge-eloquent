<?php

namespace Omnibridge\Eloquent\Tests\Support\Site;

use Omnibridge\Contract\Access;

/** Who is signed in during a flow (step F7c, third part): the account the flow says, granted what an account is. */
final class FlowAccess implements Access
{
    public ?object $user = null;

    public function isGranted(mixed $attribute, mixed $subject = null): bool
    {
        return null !== $this->user && \in_array($attribute, ['ROLE_USER', 'IS_AUTHENTICATED', 'IS_AUTHENTICATED_FULLY', 'IS_AUTHENTICATED_REMEMBERED'], true);
    }

    public function getUser(): ?object
    {
        return $this->user;
    }
}
