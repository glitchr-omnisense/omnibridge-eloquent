<?php

namespace Omnibridge\Eloquent\Tests\Support\Site;

use App\Repository\Destination\Destinations;
use Base\Enum\ThreadState;
use Omnibridge\Eloquent\Omnibase\Repository\ThreadRepository;

/**
 * lesringostars' App\Repository\Destination\Destinations on Eloquent, for the newsletter's form in the flows compared
 * at step F7c (third part): subscribable() as DestinationRepository's query says it (published, by id). Loaded in
 * lesringostars' suite only; an application's own Eloquent repositories are step F7f's.
 */
final class EloquentDestinations extends ThreadRepository implements Destinations
{
    public function subscribable(): array
    {
        return $this->findBy(['state' => ThreadState::PUBLISH], ['id' => 'ASC']);
    }
}
