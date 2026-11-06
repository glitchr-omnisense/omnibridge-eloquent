<?php

namespace Omnibridge\Eloquent\Omnibase\Repository\Layout;

use Base\Entity\Layout\Widget;
use Base\Repository\Layout\Widgets;
use Omnibridge\Eloquent\Omnibase\EloquentEntities;

/**
 * omnibridge/doctrine's Layout\WidgetRepository on Eloquent (plan "omnibase agnostic", step F7b): the same finders, compiled by
 * EloquentFinder.
 *
 * @extends EloquentEntities<Widget>
 */
class WidgetRepository extends EloquentEntities implements Widgets
{
    public function oneByPath(string $path, bool $cached = true): ?Widget
    {
        return $cached ? $this->cacheOneByPath($path) : $this->findOneByPath($path);
    }

    public function every(bool $cached = true): array
    {
        return $cached ? $this->cacheAll()->getResult() : $this->findAll();
    }
}
