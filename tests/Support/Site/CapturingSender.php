<?php

namespace Omnibridge\Eloquent\Tests\Support\Site;

use Omniauth\Contract\Sender;
use Omniauth\Message;

/** omniauth's messages kept rather than mailed (step F7c, third part): the sign-in link's secret, for the flow to follow it. */
final class CapturingSender implements Sender
{
    /** @var list<Message> */
    public array $messages = [];

    public function send(object $account, Message $message): void
    {
        $this->messages[] = $message;
    }
}
