<?php

namespace Omnibridge\Eloquent\Omnibase\Repository\Layout\Widget;

use Base\Entity\Layout\Widget\Slot;
use Base\Repository\Layout\Widget\Slots;
use Omnibridge\Eloquent\Omnibase\Repository\Layout\WidgetRepository;

/**
 * omnibridge/doctrine's Layout\Widget\SlotRepository on Eloquent (plan "omnibase agnostic", step F7b): the same finders, compiled by
 * EloquentFinder.
 *
 * @extends EloquentEntities<Slot>
 */
class SlotRepository extends WidgetRepository implements Slots
{
    public function oneByPath(string $path, bool $cached = true): ?Slot
    {
        return $cached ? $this->cacheOneByPath($path) : $this->findOneByPath($path);
    }
}
