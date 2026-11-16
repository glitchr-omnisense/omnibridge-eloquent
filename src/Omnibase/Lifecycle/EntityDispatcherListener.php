<?php

namespace Omnibridge\Eloquent\Omnibase\Lifecycle;

use Base\EntityDispatcher\AbstractEventDispatcher;
use Omnibridge\Eloquent\UnsupportedPersistence;
use Omnibridge\Persistence\Lifecycle;

/**
 * omnibase's entity dispatchers on omnibridge/eloquent: an AbstractEventDispatcher (the user.* and thread.* events, an
 * application's own) called at the moments Doctrine calls it - before a write it notes its events, after it
 * dispatches them. The dispatcher is given the unit of work's own Lifecycle events (AbstractEventDispatcher::lifecycle(),
 * its neutral hooks persisting(), updating(), removing()). The dispatcher is made on the Eloquent unit of work (its
 * hydrator's snapshots are the store's); its pending flush is the host's, as under Doctrine
 * (AbstractEventDispatcher::live()).
 *
 * A dispatcher that overrides one of the Doctrine-typed hooks (prePersist() ... postRemove(), postFlush()) is refused
 * aloud: nothing calls them here, so its events would be lost in silence - it moves to the neutral hooks.
 */
final class EntityDispatcherListener
{
    /** The hooks typed on Doctrine's event arguments, which only Doctrine's event manager calls. */
    private const DOCTRINE_HOOKS = ['prePersist', 'preUpdate', 'preRemove', 'postPersist', 'postUpdate', 'postRemove', 'postFlush'];

    public function __construct(private readonly AbstractEventDispatcher $dispatcher)
    {
        $overridden = array_values(array_filter(
            self::DOCTRINE_HOOKS,
            fn (string $hook): bool => method_exists($dispatcher, $hook) && AbstractEventDispatcher::class !== (new \ReflectionMethod($dispatcher, $hook))->getDeclaringClass()->getName(),
        ));
        if ([] !== $overridden) {
            throw UnsupportedPersistence::operation(sprintf('run %s on Eloquent', $dispatcher::class), sprintf('it overrides %s(), typed on Doctrine\'s event arguments: move it to the neutral hooks (persisting(), updating(), removing())', implode('(), ', $overridden)));
        }
    }

    public function dispatcher(): AbstractEventDispatcher
    {
        return $this->dispatcher;
    }

    public function __invoke(object $event): void
    {
        if ($event instanceof Lifecycle\EntityEvent) {
            $this->dispatcher->lifecycle($event);
        }
    }
}
