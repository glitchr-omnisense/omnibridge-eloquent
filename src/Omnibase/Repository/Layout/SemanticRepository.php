<?php

namespace Omnibridge\Eloquent\Omnibase\Repository\Layout;

use Base\Entity\Layout\Semantic;
use Base\Repository\Layout\Semantics;
use Omnibridge\Eloquent\Omnibase\EloquentEntities;

/**
 * omnibridge/doctrine's Layout\SemanticRepository on Eloquent (plan "omnibase agnostic", step F7b): the same finders, compiled by
 * EloquentFinder.
 *
 * @extends EloquentEntities<Semantic>
 */
class SemanticRepository extends EloquentEntities implements Semantics
{
    public function every(): array
    {
        return $this->cacheAll()->getResult();
    }
}
