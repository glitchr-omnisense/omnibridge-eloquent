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
 * calls it - prePersist(), preUpdate(), preRemove() note the events, postPersist(), postUpdate(), postRemove() dispatch
 * them - with doctrine/persistence's LifecycleEventArgs, which their hooks are typed on, over the unit of work
 * (EloquentObjectManager; a PreUpdate's carry its changes, PreUpdateArgs). The dispatcher is made on the Eloquent
 * unit of work (its hydrator's snapshots are the store's); its pending flush is the host's, as under Doctrine
 * (AbstractEventDispatcher::live()). Without doctrine/persistence, refused aloud. A dispatcher overriding the
 * Doctrine-typed postFlush() is refused too: there is no PostFlushEventArgs to give it.
 */
final class EntityDispatcherListener
{
    private readonly EloquentObjectManager $objectManager;

    public function __construct(private readonly AbstractEventDispatcher $dispatcher, EloquentUnitOfWork $unitOfWork)
    {
        if (!class_exists(LifecycleEventArgs::class)) {
            throw UnsupportedPersistence::operation(sprintf('run %s without doctrine/persistence', $dispatcher::class), 'omnibase\'s entity dispatchers take its LifecycleEventArgs (installed with omnibridge/doctrine)');
        }
        if (AbstractEventDispatcher::class !== (new \ReflectionMethod($dispatcher, 'postFlush'))->getDeclaringClass()->getName()) {
            throw UnsupportedPersistence::operation(sprintf('%s::postFlush(), a Doctrine-typed hook', $dispatcher::class), 'its pending events are flushed by the host (flushPending())');
        }
        $this->objectManager = new EloquentObjectManager($unitOfWork);
    }

    public function dispatcher(): AbstractEventDispatcher
    {
        return $this->dispatcher;
    }

    public function __invoke(object $event): void
    {
        if (!$event instanceof Lifecycle\EntityEvent) {
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
