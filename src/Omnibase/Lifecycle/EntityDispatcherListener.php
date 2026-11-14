<?php

namespace Omnibridge\Eloquent\Omnibase\Lifecycle;

use Base\EntityDispatcher\AbstractEventDispatcher;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Omnibridge\Eloquent\EloquentUnitOfWork;
use Omnibridge\Eloquent\UnsupportedPersistence;
use Omnibridge\Persistence\Lifecycle;

/**
 * omnibase's entity dispatchers on omnibridge/eloquent (plan "omnibase agnostic", step F7c): an AbstractEventDispatcher
 * (the user.* and thread.* events, an application's own: lesringostars' newsletter.*) called at the moments Doctrine
 * calls it - before a write it notes its events, after it dispatches them.
 *
 * On the core's neutral hooks (persisting(), updating(), removing(); step B, leftover (a)) the dispatcher is given the
 * unit of work's own Lifecycle events (AbstractEventDispatcher::lifecycle()): nothing of doctrine/persistence. One that
 * still overrides a former hook typed on doctrine/persistence's LifecycleEventArgs (AbstractEventDispatcher::formerHooks()
 * under omnisense/omnibase 5.x; 6.0 has no former hook,
 * or every dispatcher under a core without lifecycle()) is called the former way, with those arguments over the unit of
 * work (EloquentObjectManager; a PreUpdate's carry its changes, PreUpdateArgs) - refused aloud without
 * doctrine/persistence. The dispatcher is made on the Eloquent unit of work (its hydrator's snapshots are the store's);
 * its pending flush is the host's, as under Doctrine (AbstractEventDispatcher::live()). A dispatcher overriding the
 * Doctrine-typed postFlush() is refused: nothing calls it here, and its pending events are flushed by the host.
 */
final class EntityDispatcherListener
{
    /** Whether the dispatcher is called the former way, with doctrine/persistence's LifecycleEventArgs. */
    private readonly bool $former;

    private readonly ?EloquentObjectManager $objectManager;

    public function __construct(private readonly AbstractEventDispatcher $dispatcher, EloquentUnitOfWork $unitOfWork)
    {
        // omnisense/omnibase 6.0 has no former hook any more (no formerHooks()): every dispatcher is on the neutral ones.
        /** @var list<string> $former */
        $former = match (true) {
            !method_exists(AbstractEventDispatcher::class, 'lifecycle') => ['a core without lifecycle(): onPersist'],
            method_exists(AbstractEventDispatcher::class, 'formerHooks') => AbstractEventDispatcher::formerHooks($dispatcher),
            default => [],
        };
        if ([] !== $former && !class_exists(LifecycleEventArgs::class)) {
            throw UnsupportedPersistence::operation(sprintf('run %s without doctrine/persistence', $dispatcher::class), sprintf('it overrides %s(), typed on doctrine/persistence\'s LifecycleEventArgs: move it to the neutral hooks (persisting(), updating(), removing()) or install doctrine/persistence', implode('(), ', $former)));
        }
        if (AbstractEventDispatcher::class !== (new \ReflectionMethod($dispatcher, 'postFlush'))->getDeclaringClass()->getName()) {
            throw UnsupportedPersistence::operation(sprintf('%s::postFlush(), a Doctrine-typed hook', $dispatcher::class), 'its pending events are flushed by the host (flushPending())');
        }
        $this->former = [] !== $former;
        $this->objectManager = $this->former ? new EloquentObjectManager($unitOfWork) : null;
    }

    public function dispatcher(): AbstractEventDispatcher
    {
        return $this->dispatcher;
    }

    /** Whether the dispatcher is called with doctrine/persistence's LifecycleEventArgs (a former hook overridden). */
    public function former(): bool
    {
        return $this->former;
    }

    public function __invoke(object $event): void
    {
        if (!$event instanceof Lifecycle\EntityEvent) {
            return;
        }
        if (!$this->former) {
            $this->dispatcher->lifecycle($event);

            return;
        }
        match (true) {
            $event instanceof Lifecycle\PrePersist => $this->dispatcher->prePersist($this->args($event)),
            $event instanceof Lifecycle\PreUpdate => $this->dispatcher->preUpdate(new PreUpdateArgs($event->entity, $this->objectManager, $event->changes())),
            $event instanceof Lifecycle\PreRemove => $this->dispatcher->preRemove($this->args($event)),
            $event instanceof Lifecycle\PostPersist => $this->dispatcher->postPersist($this->args($event)),
            $event instanceof Lifecycle\PostUpdate => $this->dispatcher->postUpdate($this->args($event)),
            $event instanceof Lifecycle\PostRemove => $this->dispatcher->postRemove($this->args($event)),
            default => null,
        };
    }

    private function args(Lifecycle\EntityEvent $event): LifecycleEventArgs
    {
        return new LifecycleEventArgs($event->entity, $this->objectManager);
    }
}
