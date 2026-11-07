<?php

namespace Omnibridge\Eloquent\Omnibase\Lifecycle;

use Base\Entity\Layout\TextOverride;
use Omnibridge\Persistence\Lifecycle;

/**
 * The translations' overrides kept in step on omnibridge/eloquent (plan "omnibase agnostic", step F7c): what omnibase's
 * TextOverrideCacheListener does on Doctrine's events - a TextOverride written, the overriding translator's cache
 * emptied once the flush is done.
 */
final class TextOverrideListener
{
    private bool $changed = false;

    /** @param \Closure(): \Base\Translation\OverridingTranslator $translator */
    public function __construct(private readonly \Closure $translator)
    {
    }

    public function __invoke(object $event): void
    {
        if ($event instanceof Lifecycle\PostPersist || $event instanceof Lifecycle\PostUpdate || $event instanceof Lifecycle\PostRemove) {
            $this->changed = $this->changed || $event->entity instanceof TextOverride;
        } elseif ($event instanceof Lifecycle\PostFlush && $this->changed) {
            $this->changed = false;
            ($this->translator)()->invalidate();
        }
    }
}
