<?php

namespace Omnibridge\Eloquent\Omnibase\Lifecycle;

use Base\EntityDispatcher\AbstractEventDispatcher;
use Omnibridge\Eloquent\EloquentUnitOfWork;
use Omnibridge\Persistence\Lifecycle;

/**
 * A host's entity dispatchers on omnibridge/eloquent (plan "omnibase agnostic", step F7d): the services its container
 * makes (Symfony's tagged base.entity_dispatcher, Laravel's, Omnibridge\Container's), on the unit of work the container
 * gives - this persistence's -, read at the first entity event, since they are made with it. Each is then called as
 * EntityDispatcherListener calls one, in their order. They are the container's, not made here: their life is the
 * container's, the one this persistence lives in, so the host's flush of what they left pending (flushPending(),
 * AbstractEventDispatcher::live()) never reaches a unit of work another persistence holds.
 */
final class EntityDispatchersListener
{
    /** @var list<EntityDispatcherListener>|null */
    private ?array $listeners = null;

    /** @param iterable<object>|(\Closure(): iterable<object>) $dispatchers */
    public function __construct(private readonly iterable|\Closure $dispatchers, private readonly EloquentUnitOfWork $unitOfWork)
    {
    }

    public function __invoke(object $event): void
    {
        if (!$event instanceof Lifecycle\EntityEvent) {
            return;
        }
        foreach ($this->listeners() as $listener) {
            $listener($event);
        }
    }

    /** @return list<EntityDispatcherListener> one per dispatcher, in their order */
    public function listeners(): array
    {
        if (null !== $this->listeners) {
            return $this->listeners;
        }
        $dispatchers = $this->dispatchers instanceof \Closure ? ($this->dispatchers)() : $this->dispatchers;
        $listeners = [];
        foreach ($dispatchers ?? [] as $dispatcher) {
            if (!$dispatcher instanceof AbstractEventDispatcher) {
                throw new \InvalidArgumentException(sprintf('An entity dispatcher is an %s, not a %s.', AbstractEventDispatcher::class, get_debug_type($dispatcher)));
            }
            $listeners[] = new EntityDispatcherListener($dispatcher, $this->unitOfWork);
        }

        return $this->listeners = $listeners;
    }

    /** @return list<AbstractEventDispatcher> */
    public function dispatchers(): array
    {
        return array_map(static fn (EntityDispatcherListener $listener): AbstractEventDispatcher => $listener->dispatcher(), $this->listeners());
    }
}
