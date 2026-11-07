<?php

namespace Omnibridge\Eloquent\Omnibase;

use Base\Attributes\AbstractAttribute;
use Base\Attributes\AttributeReader;
use Omnibridge\Eloquent\EloquentUnitOfWork;
use Omnibridge\Eloquent\UnsupportedPersistence;
use Omnibridge\Persistence\Lifecycle;

/**
 * omnibase's behaviour attributes on omnibridge/eloquent's unit of work (plan "omnibase agnostic", step F7c): what
 * omnibridge/doctrine's AttributeSubscriber does on Doctrine's events, on the Lifecycle events the unit of work
 * dispatches at the same moments - a listener of it (EloquentUnitOfWork::listen()).
 *
 * - The entity's moments (PrePersist ... PostRemove, PostLoad): each property attribute of the entity's class, then
 *   each class attribute, that supports the entity, gets its neutral hook (onPrePersist()...). A PreUpdate's
 *   setNewValue() is the unit of work's to write (it reads the event's changes back).
 * - The flush's moments, over buffers kept exactly as AttributeSubscriber keeps them: PreFlush and OnFlush both
 *   append the scheduled insertions, updates and deletions to the same buffers without emptying them - so at OnFlush
 *   an entity persisted before the flush is in them twice and its OnFlush hooks run twice, as on Doctrine -; a
 *   property attribute runs when its property is in the entity's changes or, for a new entity without an identifier
 *   and no changes, in its properties (cast_to_array()); a class attribute that ran at PreFlush makes its entity a
 *   candidate; PostFlush walks the candidates and the buffers, then empties them all. Doing otherwise changes rows
 *   (two slugs, two uuids).
 * - An attribute that overrides a Doctrine-typed hook of the moment relayed (prePersist(), onFlush()...: an
 *   application's or a bundle's, omnibase/marketplace's OrderReference) is refused aloud: there is no Doctrine event
 *   to give it.
 */
final class AttributeRelay
{
    private const ENTITY_HOOKS = [
        Lifecycle\PrePersist::class => ['onPrePersist', 'prePersist'],
        Lifecycle\PreUpdate::class => ['onPreUpdate', 'preUpdate'],
        Lifecycle\PreRemove::class => ['onPreRemove', 'preRemove'],
        Lifecycle\PostLoad::class => ['onPostLoad', 'postLoad'],
        Lifecycle\PostPersist::class => ['onPostPersist', 'postPersist'],
        Lifecycle\PostUpdate::class => ['onPostUpdate', 'postUpdate'],
        Lifecycle\PostRemove::class => ['onPostRemove', 'postRemove'],
    ];

    /** @var list<object> */
    private array $entityCandidateBuffer = [];

    /** @var list<object> */
    private array $entityInsertionBuffer = [];

    /** @var list<object> */
    private array $entityUpdateBuffer = [];

    /** @var list<object> */
    private array $entityDeletionBuffer = [];

    /** @var array<string, bool> attribute class::Doctrine hook => whether it overrides AbstractAttribute's */
    private static array $overrides = [];

    public function __construct(private readonly AttributeReader $attributeReader)
    {
        EloquentBehaviours::ensureRegistered();
    }

    /** The relay listening to the unit of work's events. */
    public static function attach(EloquentUnitOfWork $unitOfWork, ?AttributeReader $attributeReader = null): self
    {
        $attributeReader ??= AttributeReader::hasInstance() ? AttributeReader::getInstance(false) : null;
        $relay = new self($attributeReader ?? throw new \LogicException('No omnibase AttributeReader to relay the behaviours with (it is made by the host: omnibridge/symfony, omnibridge/laravel).'));
        $unitOfWork->listen($relay(...));

        return $relay;
    }

    public function __invoke(object $event): void
    {
        match (true) {
            $event instanceof Lifecycle\PreFlush => $this->preFlush($event),
            $event instanceof Lifecycle\OnFlush => $this->onFlush($event),
            $event instanceof Lifecycle\PostFlush => $this->postFlush($event),
            $event instanceof Lifecycle\EntityEvent => $this->onLifecycle($event),
            default => null,
        };
    }

    private function preFlush(Lifecycle\PreFlush $event): void
    {
        $unitOfWork = $this->unitOfWork($event);
        $this->buffer($unitOfWork);

        foreach (array_merge($this->entityInsertionBuffer, $this->entityUpdateBuffer, $this->entityDeletionBuffer) as $entity) {
            $className = $entity::class;
            $attributes = $this->attributeReader->getAttributes($className);
            $changeSet = $this->changeSet($unitOfWork, $entity);

            foreach ($attributes[AttributeReader::TARGET_PROPERTY][$className] ?? [] as $property => $propertyAttributes) {
                if (!\array_key_exists($property, $changeSet)) {
                    continue;
                }
                foreach ($propertyAttributes as $attribute) {
                    if (!$this->applies($attribute, AttributeReader::TARGET_PROPERTY, $property, $entity)) {
                        continue;
                    }
                    $this->refuseDoctrineHook($attribute, 'preFlush');
                    $attribute->onPreFlush($event, $entity, $property);
                }
            }

            foreach ($attributes[AttributeReader::TARGET_CLASS][$className] ?? [] as $attribute) {
                if (!$this->applies($attribute, AttributeReader::TARGET_CLASS, $className, $entity)) {
                    continue;
                }
                $this->refuseDoctrineHook($attribute, 'preFlush');
                $attribute->onPreFlush($event, $entity);
                $this->entityCandidateBuffer[] = $entity;
            }
        }
    }

    private function onFlush(Lifecycle\OnFlush $event): void
    {
        $unitOfWork = $this->unitOfWork($event);
        $this->buffer($unitOfWork);

        foreach (array_merge($this->entityInsertionBuffer, $this->entityUpdateBuffer, $this->entityDeletionBuffer) as $entity) {
            $className = $entity::class;
            $attributes = $this->attributeReader->getAttributes($className);
            $changeSet = $this->changeSet($unitOfWork, $entity);

            foreach ($attributes[AttributeReader::TARGET_PROPERTY][$className] ?? [] as $property => $propertyAttributes) {
                if (!\array_key_exists($property, $changeSet)) {
                    continue;
                }
                foreach ($propertyAttributes as $attribute) {
                    if (!$this->applies($attribute, AttributeReader::TARGET_PROPERTY, $property, $entity)) {
                        continue;
                    }
                    $this->refuseDoctrineHook($attribute, 'onFlush');
                    $attribute->onOnFlush($event, $entity, $property);
                }
            }

            foreach ($attributes[AttributeReader::TARGET_CLASS][$className] ?? [] as $attribute) {
                if (!$this->applies($attribute, AttributeReader::TARGET_CLASS, $className, $entity)) {
                    continue;
                }
                $this->refuseDoctrineHook($attribute, 'onFlush');
                $attribute->onOnFlush($event, $entity);
            }
        }
    }

    private function postFlush(Lifecycle\PostFlush $event): void
    {
        $unitOfWork = $this->unitOfWork($event);
        foreach (array_merge($this->entityCandidateBuffer, $this->entityInsertionBuffer, $this->entityUpdateBuffer, $this->entityDeletionBuffer) as $entity) {
            $className = $entity::class;
            $attributes = $this->attributeReader->getAttributes($className);
            $changeSet = $this->changeSet($unitOfWork, $entity);

            foreach ($attributes[AttributeReader::TARGET_PROPERTY][$className] ?? [] as $property => $propertyAttributes) {
                if (!\array_key_exists($property, $changeSet)) {
                    continue;
                }
                foreach ($propertyAttributes as $attribute) {
                    if (!$this->applies($attribute, AttributeReader::TARGET_PROPERTY, $property, $entity)) {
                        continue;
                    }
                    $this->refuseDoctrineHook($attribute, 'postFlush');
                    $attribute->onPostFlush($event, $entity, $property);
                }
            }

            foreach ($attributes[AttributeReader::TARGET_CLASS][$className] ?? [] as $attribute) {
                if (!$this->applies($attribute, AttributeReader::TARGET_CLASS, $className, $entity)) {
                    continue;
                }
                $this->refuseDoctrineHook($attribute, 'postFlush');
                $attribute->onPostFlush($event, $entity);
            }
        }

        // Emptied once every hook ran (a hook that throws leaves them, as AttributeSubscriber does).
        $this->entityInsertionBuffer = [];
        $this->entityUpdateBuffer = [];
        $this->entityDeletionBuffer = [];
        $this->entityCandidateBuffer = [];
    }

    private function onLifecycle(Lifecycle\EntityEvent $event): void
    {
        [$hook, $doctrineHook] = self::ENTITY_HOOKS[$event::class] ?? [null, null];
        if (null === $hook) {
            return;
        }
        $entity = $event->entity;
        $className = $entity::class;
        $attributes = $this->attributeReader->getAttributes($className);

        foreach ($attributes[AttributeReader::TARGET_PROPERTY][$className] ?? [] as $property => $propertyAttributes) {
            foreach ($propertyAttributes as $attribute) {
                if (!$this->applies($attribute, AttributeReader::TARGET_PROPERTY, $property, $entity)) {
                    continue;
                }
                $this->refuseDoctrineHook($attribute, $doctrineHook);
                $attribute->{$hook}($event, $property);
            }
        }

        foreach ($attributes[AttributeReader::TARGET_CLASS][$className] ?? [] as $attribute) {
            if (!$this->applies($attribute, AttributeReader::TARGET_CLASS, $className, $entity)) {
                continue;
            }
            $this->refuseDoctrineHook($attribute, $doctrineHook);
            $attribute->{$hook}($event);
        }
    }

    /** The scheduled writes appended to the buffers, which nothing empties before PostFlush (AttributeSubscriber's). */
    private function buffer(EloquentUnitOfWork $unitOfWork): void
    {
        foreach ($unitOfWork->scheduledInsertions() as $entity) {
            $this->entityInsertionBuffer[] = $entity;
        }
        foreach ($unitOfWork->scheduledUpdates() as $entity) {
            $this->entityUpdateBuffer[] = $entity;
        }
        foreach ($unitOfWork->scheduledDeletions() as $entity) {
            $this->entityDeletionBuffer[] = $entity;
        }
    }

    /** The entity's changes, or its properties for a new entity without an identifier and no changes yet. */
    private function changeSet(EloquentUnitOfWork $unitOfWork, object $entity): array
    {
        $changeSet = $unitOfWork->changes($entity);
        if (empty($changeSet) && !$entity->getId()) {
            $changeSet = cast_to_array($entity);
        }

        return $changeSet;
    }

    private function applies(mixed $attribute, string $target, string $targetValue, object $entity): bool
    {
        return is_subclass_of($attribute, AbstractAttribute::class)
            && \in_array($target, $this->attributeReader->getAttributeTargets($attribute), true)
            && $attribute->supports($target, $targetValue, $entity);
    }

    private function unitOfWork(Lifecycle\FlushEvent $event): EloquentUnitOfWork
    {
        if (!$event->unitOfWork instanceof EloquentUnitOfWork) {
            throw new \LogicException(\sprintf('The relay listens to omnibridge/eloquent\'s unit of work, not to a %s.', get_debug_type($event->unitOfWork)));
        }

        return $event->unitOfWork;
    }

    /** An attribute whose Doctrine-typed hook of this moment is its own: it has no Doctrine event here. */
    private function refuseDoctrineHook(AbstractAttribute $attribute, string $doctrineHook): void
    {
        $key = $attribute::class.'::'.$doctrineHook;
        self::$overrides[$key] ??= AbstractAttribute::class !== (new \ReflectionMethod($attribute, $doctrineHook))->getDeclaringClass()->getName();
        if (self::$overrides[$key]) {
            throw UnsupportedPersistence::operation(
                \sprintf('%s::%s(), a Doctrine-typed hook', $attribute::class, $doctrineHook),
                \sprintf('the attribute answers the neutral one instead (on%s(), with omnisense/omnibridge\'s Lifecycle event)', ucfirst($doctrineHook)),
            );
        }
    }
}
