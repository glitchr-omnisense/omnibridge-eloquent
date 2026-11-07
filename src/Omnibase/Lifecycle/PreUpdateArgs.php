<?php

namespace Omnibridge\Eloquent\Omnibase\Lifecycle;

use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\Persistence\ObjectManager;

/**
 * doctrine/persistence's lifecycle arguments of a PreUpdate, with its changes (getEntityChangeSet(), as Doctrine's
 * PreUpdateEventArgs gives them): omnisense/omnibase's EntityHydrator reads the values they replace from them.
 *
 * @extends LifecycleEventArgs<ObjectManager>
 */
final class PreUpdateArgs extends LifecycleEventArgs
{
    /** @param array<string, array{0: mixed, 1: mixed}> $changes */
    public function __construct(object $object, ObjectManager $objectManager, private readonly array $changes)
    {
        parent::__construct($object, $objectManager);
    }

    /** @return array<string, array{0: mixed, 1: mixed}> */
    public function getEntityChangeSet(): array
    {
        return $this->changes;
    }
}
