<?php

namespace Omnibridge\Eloquent\Omnibase\Repository\Layout;

use Base\Entity\Layout\TextOverride;
use Omnibridge\Eloquent\Omnibase\EloquentEntities;

/**
 * omnibridge/doctrine's Layout\TextOverrideRepository on Eloquent (plan "omnibase agnostic", step F7b): no interface
 * of the core's names it, but omnibase's OverridingTranslator calls its map().
 *
 * @extends EloquentEntities<TextOverride>
 */
class TextOverrideRepository extends EloquentEntities
{
    /** @return array<string, string> "domain|locale|key" => the text */
    public function map(): array
    {
        $map = [];
        foreach ($this->findAll() as $override) {
            $map[$override->getDomain().'|'.$override->getLocale().'|'.$override->getKey()] = $override->getValue();
        }

        return $map;
    }
}
