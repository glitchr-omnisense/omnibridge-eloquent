<?php

namespace Omnibridge\Eloquent;

use Doctrine\Common\Collections\Collection;
use Omnibridge\Persistence\Lifecycle;
use Omnibridge\Persistence\Snapshots;
use Omnibridge\Persistence\UnitOfWork;
use Omnibridge\Schema\Relation;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * omnisense/omnibridge's unit of work on the store, writing as Doctrine's unit of work writes (plan "omnibase
 * agnostic", step F7c), so that the same entities given the same calls leave the same rows on both backends.
 *
 * - persist(): a new entity scheduled for insertion (PrePersist), and the entities its associations reach where
 *   they cascade the persist; remove(): the entities its associations cascade the removal to first, then the
 *   entity scheduled for deletion (PreRemove); persisting a removed entity takes it back.
 * - flush(), in Doctrine's order (its UnitOfWork::commit()):
 *   1. PreFlush;
 *   2. the change sets: each new entity's fields and owning to-one associations; each managed entity's values
 *      compared with what it held when read or last flushed (the store's snapshot), strictly (`!==`, an object
 *      by identity); new entities reached through an association persisted when it cascades (a PrePersist each),
 *      refused otherwise (InvalidPersistence::notCascaded()); the owning many-to-many collections compared with
 *      their snapshot (a managed owner with no other change is updated with an empty change set, as Doctrine
 *      does); the entities taken out of an orphan-removing association removed;
 *   3. OnFlush (a listener may persist, remove, change and recompute);
 *   4. in one transaction: the inserts in commit order (CommitOrder: an entity after the new entities its
 *      foreign keys point to; a nullable one in a cycle written NULL then updated), each identifier the database
 *      gives set on its entity, then PostPersist for each; the updates by class and identifier, each after its
 *      PreUpdate (whose setNewValue() values are written, and whose listeners' direct changes are recomputed, as
 *      Doctrine recomputes after preUpdate), then PostUpdate; the extra updates; the join tables' rows; the deletes
 *      in reverse dependency order, then PostRemove for each;
 *   5. the snapshots taken again (a collection the application made becomes a LazyCollection, as Doctrine puts a
 *      PersistentCollection in its place), the removed entities taken out of the collections; PostFlush.
 *   A flush that fails is rolled back and closes the unit of work, as Doctrine closes its entity manager.
 * - PostLoad for each entity the store reads, once the read is done.
 * - The events are omnisense/omnibridge's Lifecycle events, given to the listeners (listen()) and to a PSR-14
 *   dispatcher: the behaviours of an application (omnibase's attributes) run on them, whichever backend writes.
 * - transactional(): the work, then a flush, in one transaction.
 * - Snapshots::original(): the store's snapshot.
 *
 * What is not done is refused aloud (UnsupportedPersistence): an identifier generated otherwise than by the
 * database or the application, a composite identifier or join column.
 */
final class EloquentUnitOfWork implements UnitOfWork, Snapshots
{
    private readonly Persister $persister;

    private readonly Cascades $cascades;

    /** @var list<\Closure(object): void> */
    private array $listeners = [];

    /** @var array<int, object> new entities to insert, in the order they were scheduled */
    private array $insertions = [];

    /** @var array<int, object> */
    private array $updates = [];

    /** @var array<int, object> */
    private array $deletions = [];

    /** @var array<int, array<string, array{0: mixed, 1: mixed}>> */
    private array $changeSets = [];

    /** @var array<int, array{0: object, 1: array<string, array{0: mixed, 1: mixed}>}> */
    private array $extraUpdates = [];

    /** @var array<string, array{0: object, 1: Relation, 2: list<object>, 3: list<object>, 4: bool}> owner#property => the join rows to write */
    private array $collectionWrites = [];

    /** @var array<int, object> */
    private array $orphans = [];

    /** @var array<string, array{0: object, 1: string, 2: mixed}> owner#property => the owner, the property, the collection: the to-many collections this flush finds changed */
    private array $collectionUpdates = [];

    /** @var array<int, array{0: string, 1: string, 2: object}> new entities met through an association that does not cascade */
    private array $notCascaded = [];

    /** @var array<int, array{0: object, 1: string}> entities whose collections hold an entity this flush deletes */
    private array $visited = [];

    /**
     * @var array<int, array<string, mixed>> what an entity this flush inserted or updated held when its change set was
     * computed (or recomputed after PreUpdate): its snapshot after the commit, as Doctrine keeps that as its original
     * data - a change a PostPersist, PostUpdate or PostFlush listener makes is written by the next flush
     */
    private array $computed = [];

    /** @var array<int, true> entities whose row this flush wrote, to snapshot */
    private array $written = [];

    private bool $flushing = false;

    private bool $closed = false;

    public function __construct(
        private readonly EntityStore $store,
        private readonly ?EventDispatcherInterface $dispatcher = null,
        ?Cascades $cascades = null,
    ) {
        $this->persister = new Persister($store);
        $this->cascades = $cascades ?? new Cascades();
        $store->onLoad(function (object $entity): void {
            $this->dispatch(new Lifecycle\PostLoad($entity, $this));
        });
    }

    public function store(): EntityStore
    {
        return $this->store;
    }

    public function cascades(): Cascades
    {
        return $this->cascades;
    }

    /**
     * A listener of the Lifecycle events (PrePersist ... PostFlush, PostLoad), called before the PSR-14 dispatcher.
     *
     * @param \Closure(object): void $listener
     */
    public function listen(\Closure $listener): void
    {
        $this->listeners[] = $listener;
    }

    public function persist(object $entity): void
    {
        $this->open();
        $visited = [];
        $this->doPersist($entity, $visited);
    }

    public function remove(object $entity): void
    {
        $this->open();
        $visited = [];
        $this->doRemove($entity, $visited);
    }

    public function flush(): void
    {
        $this->open();
        if ($this->flushing) {
            throw UnsupportedPersistence::operation('flush during a flush', 'a listener of a flush schedules writes for it (persist, remove, recompute) instead');
        }
        $this->flushing = true;
        try {
            $this->commit();
        } finally {
            $this->flushing = false;
            $this->cleanup();
        }
    }

    public function refresh(object $entity): void
    {
        if (!$this->store->identityMap()->contains($entity)) {
            throw new \InvalidArgumentException(\sprintf('The %s to refresh was not read through this unit of work.', $entity::class));
        }
        if (!$this->store->refresh($entity)) {
            throw EntityNotFound::of($entity::class, $this->store->identifier($entity));
        }
    }

    public function contains(object $entity): bool
    {
        $oid = spl_object_id($entity);

        return ($this->store->identityMap()->contains($entity) || isset($this->insertions[$oid])) && !isset($this->deletions[$oid]);
    }

    public function reference(string $class, mixed $id): object
    {
        return $this->store->reference($class, $id) ?? throw EntityNotFound::of($class, $id);
    }

    public function transactional(callable $work): mixed
    {
        $this->open();
        $connection = $this->store->connection();
        $connection->beginTransaction();
        try {
            $result = $work($this);
            $this->flush();
            $connection->commit();

            return $result;
        } catch (\Throwable $failure) {
            $this->closed = true;
            $connection->rollBack();

            throw $failure;
        }
    }

    public function isNew(object $entity): bool
    {
        $oid = spl_object_id($entity);

        return isset($this->insertions[$oid]) || (!$this->store->identityMap()->contains($entity) && !isset($this->deletions[$oid]));
    }

    public function identifier(object $entity): mixed
    {
        return $this->store->mapping()->has($entity::class) ? $this->store->identifier($entity) : null;
    }

    public function className(object $entity): string
    {
        // A lazy ghost is of the entity's own class: nothing to see through.
        return $entity::class;
    }

    public function original(object $entity): array
    {
        return $this->store->original($entity);
    }

    public function changes(object $entity): array
    {
        return $this->changeSets[spl_object_id($entity)] ?? [];
    }

    /**
     * Doctrine's recomputeSingleEntityChangeSet(): the entity's fields and owning to-one associations compared again
     * with what they were when its change set was computed, the changes found added (they prevail). A new entity
     * persisted during the flush whose change set was not computed yet gets its first one.
     */
    public function recompute(object $entity): void
    {
        $oid = spl_object_id($entity);
        if (isset($this->insertions[$oid]) && !isset($this->changeSets[$oid])) {
            $this->computeChangeSet($entity);

            return;
        }
        if (!$this->contains($entity)) {
            throw InvalidPersistence::notManaged($entity, 'recompute');
        }
        $map = $this->store->mapping()->of($entity::class);
        $original = $this->store->original($entity);
        $changeSet = [];
        foreach ($this->actual($map, $entity, false) as $property => $value) {
            $before = $original[$property] ?? null;
            if (!self::same($before, $value)) {
                $changeSet[$property] = [$before, $value];
            }
        }
        if ([] === $changeSet) {
            return;
        }
        if (isset($this->changeSets[$oid])) {
            $this->changeSets[$oid] = [...$this->changeSets[$oid], ...$changeSet];
        } elseif (!isset($this->insertions[$oid])) {
            $this->changeSets[$oid] = $changeSet;
            $this->updates[$oid] = $entity;
        }
        foreach ($changeSet as $property => [, $after]) {
            $this->store->setOriginal($entity, $property, $after);
        }
    }

    /** Whether the entity is scheduled for insertion (persisted, not flushed). */
    public function isScheduledForInsert(object $entity): bool
    {
        return isset($this->insertions[spl_object_id($entity)]);
    }

    /** Whether the entity is scheduled for deletion (removed, not flushed). */
    public function isScheduledForDelete(object $entity): bool
    {
        return isset($this->deletions[spl_object_id($entity)]);
    }

    /** @return list<object> the entities the running flush inserts */
    public function scheduledInsertions(): array
    {
        return array_values($this->insertions);
    }

    /** @return list<object> the entities the running flush updates */
    public function scheduledUpdates(): array
    {
        return array_values($this->updates);
    }

    /** @return list<object> the entities the running flush deletes */
    public function scheduledDeletions(): array
    {
        return array_values($this->deletions);
    }

    /**
     * An update written after the others (Doctrine's scheduleExtraUpdate()): the columns of the change set, with no
     * PreUpdate nor PostUpdate - what a listener of OnFlush writes beside the flush (a stamp on an entity whose
     * deletion it cancels).
     *
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet
     */
    public function scheduleExtraUpdate(object $entity, array $changeSet): void
    {
        $oid = spl_object_id($entity);
        $this->extraUpdates[$oid] = [$entity, $changeSet + ($this->extraUpdates[$oid][1] ?? [])];
    }

    /**
     * The entity updated by this flush whatever its change set says (Doctrine's scheduleForUpdate()): its PreUpdate
     * and PostUpdate dispatched, its row written when a listener changed it - what #[Vault] asks at PreFlush for a
     * secured field given its plain value again. A managed entity only.
     */
    public function scheduleForUpdate(object $entity): void
    {
        $oid = spl_object_id($entity);
        if (!$this->store->identityMap()->contains($entity)) {
            throw InvalidPersistence::notManaged($entity, 'scheduleForUpdate');
        }
        if (isset($this->deletions[$oid])) {
            throw new InvalidPersistence(\sprintf('The %s scheduled for deletion cannot be scheduled for an update.', $entity::class));
        }
        if (!isset($this->updates[$oid]) && !isset($this->insertions[$oid])) {
            $this->updates[$oid] = $entity;
        }
    }

    /**
     * The entity deleted by this flush, no PreRemove dispatched and nothing cascaded (Doctrine's scheduleForDelete()):
     * what a listener of the flush asks for an entity it found empty (an empty translation).
     */
    public function scheduleForDelete(object $entity): void
    {
        $oid = spl_object_id($entity);
        if (isset($this->insertions[$oid])) {
            unset($this->insertions[$oid], $this->changeSets[$oid]);
            $this->store->forget($entity);

            return;
        }
        if (!$this->store->identityMap()->contains($entity)) {
            return;
        }
        unset($this->updates[$oid]);
        $this->deletions[$oid] = $entity;
    }

    /**
     * A property's change told by a listener (Doctrine's propertyChanged()): in the entity's change set, without
     * scheduling an update - the stamp TrasheableSubscriber writes through an extra update.
     */
    public function propertyChanged(object $entity, string $property, mixed $before, mixed $after): void
    {
        $map = $this->store->mapping()->of($entity::class);
        if (!isset($map->entity->fields[$property]) && !isset($map->entity->relations[$property])) {
            return;
        }
        $this->changeSets[spl_object_id($entity)][$property] = [$before, $after];
    }

    /** What the unit of work remembers of one property, moved forward (Doctrine's setOriginalEntityProperty()). */
    public function setOriginal(object $entity, string $property, mixed $value): void
    {
        $this->store->setOriginal($entity, $property, $value);
    }

    /**
     * The to-many collections the running flush found changed (Doctrine's getScheduledCollectionUpdates()): each
     * owner, property and collection - one changed since it was read, or one the application made holding elements.
     *
     * @return list<array{0: object, 1: string, 2: mixed}>
     */
    public function scheduledCollectionUpdates(): array
    {
        return array_values($this->collectionUpdates);
    }

    /** The entity removed at the next flush as an orphan (Doctrine's scheduleOrphanRemoval()). */
    public function scheduleOrphanRemoval(object $entity): void
    {
        $this->orphans[spl_object_id($entity)] = $entity;
    }

    /** An orphan removal taken back (Doctrine's cancelOrphanRemoval()). */
    public function cancelOrphanRemoval(object $entity): void
    {
        unset($this->orphans[spl_object_id($entity)]);
    }

    /**
     * The entity's change set computed again with its associations (Doctrine's computeChangeSet() on a managed
     * entity): its fields and to-one associations against what they were when last computed - a change found replaces
     * the change set, as Doctrine's does -, then its collections (the join rows of an owning many-to-many, the new
     * entities they reach). What a listener of OnFlush asks after it changed a collection (a reply's followers).
     */
    public function recomputeWithAssociations(object $entity): void
    {
        $oid = spl_object_id($entity);
        if (isset($this->insertions[$oid]) && !isset($this->changeSets[$oid])) {
            $this->computeChangeSet($entity);

            return;
        }
        if (!$this->contains($entity)) {
            throw InvalidPersistence::notManaged($entity, 'recomputeWithAssociations');
        }
        $this->computeChangeSet($entity);
    }

    /**
     * The entity forgotten by the unit of work (Doctrine's detach()): not inserted, updated nor deleted by the flush,
     * out of the identity map; nothing cascaded.
     */
    public function detach(object $entity): void
    {
        $oid = spl_object_id($entity);
        unset($this->insertions[$oid], $this->updates[$oid], $this->deletions[$oid], $this->changeSets[$oid], $this->extraUpdates[$oid], $this->orphans[$oid]);
        $this->store->forget($entity);
    }

    /** Whether the entity is a lazy object not read yet (Doctrine's isUninitializedObject()). */
    public function isUninitializedObject(object $entity): bool
    {
        return $this->store->isUninitialized($entity);
    }

    /**
     * A removed entity taken back without its other changes being computed (Doctrine's persist() on a removed entity
     * during onFlush): it stays as its row is, unless an extra update writes it.
     */
    public function cancelDeletion(object $entity): void
    {
        unset($this->deletions[spl_object_id($entity)]);
    }

    private function doPersist(object $entity, array &$visited): void
    {
        $oid = spl_object_id($entity);
        if (isset($visited[$oid])) {
            return;
        }
        $visited[$oid] = true;
        if (!$this->store->mapping()->has($entity::class)) {
            throw InvalidPersistence::notEntity($entity);
        }

        if (isset($this->deletions[$oid])) {
            unset($this->deletions[$oid]);
        } elseif (!$this->contains($entity)) {
            $this->persistNew($entity);
        }

        $this->cascade($entity, 'persist', function (object $related) use (&$visited): void {
            $this->doPersist($related, $visited);
        });
    }

    private function persistNew(object $entity): void
    {
        $map = $this->store->mapping()->of($entity::class);
        $this->dispatch(new Lifecycle\PrePersist($entity, $this));
        if (!Persister::generated($map)) {
            foreach ($map->identifier as $field) {
                if (null !== $field->generated) {
                    throw UnsupportedPersistence::generator($map->className(), $field->generated);
                }
            }
            if (1 !== \count($map->identifier)) {
                throw UnsupportedPersistence::composite($map->className(), 'identifier');
            }
            $id = $this->store->identifier($entity);
            if (null === $id) {
                throw new InvalidPersistence(\sprintf('Entity of type %s is missing an assigned ID for field \'%s\'. The identifier generation strategy for this entity requires the ID field to be populated before persist() is called.', $map->className(), $map->identifier[0]->property));
            }
            $this->store->register($entity, $id);
        }
        $this->insertions[spl_object_id($entity)] = $entity;
    }

    private function doRemove(object $entity, array &$visited): void
    {
        $oid = spl_object_id($entity);
        if (isset($visited[$oid])) {
            return;
        }
        $visited[$oid] = true;
        if (!$this->store->mapping()->has($entity::class)) {
            throw InvalidPersistence::notEntity($entity);
        }

        // Cascade first, as Doctrine does, while what it reaches is still read through the identity map.
        $this->cascade($entity, 'remove', function (object $related) use (&$visited): void {
            $this->doRemove($related, $visited);
        }, true);

        if (isset($this->deletions[$oid]) || !$this->contains($entity)) {
            return;
        }
        $this->dispatch(new Lifecycle\PreRemove($entity, $this));
        if (isset($this->insertions[$oid])) {
            // Never written: its insertion is cancelled.
            unset($this->insertions[$oid], $this->changeSets[$oid]);
            $this->store->forget($entity);

            return;
        }
        unset($this->updates[$oid]);
        $this->deletions[$oid] = $entity;
    }

    /**
     * The operation on the entities the entity's associations cascade it to: a to-one association's entity, a
     * collection's elements - a collection never read is read for a removal (its elements are removed too) and left
     * alone for a persist (it holds no new entity), as Doctrine unwraps it.
     */
    private function cascade(object $entity, string $operation, \Closure $apply, bool $read = false): void
    {
        if ($this->store->isUninitialized($entity)) {
            if (!$read) {
                return;
            }
            (new \ReflectionClass($entity))->initializeLazyObject($entity);
        }
        $map = $this->store->mapping()->of($entity::class);
        foreach ($map->entity->relations as $property => $relation) {
            if (!$this->cascades->cascades($map->className(), $relation, $operation)) {
                continue;
            }
            $value = $this->store->value($entity, $property);
            if (null === $value) {
                continue;
            }
            if ($value instanceof LazyCollection && !$value->isInitialized() && !$value->isDirty() && !$read) {
                continue;
            }
            foreach ($relation->isToOne() ? [$value] : $value as $related) {
                if (\is_object($related)) {
                    $apply($related);
                }
            }
        }
    }

    private function commit(): void
    {
        $this->dispatch(new Lifecycle\PreFlush($this));
        $this->computeChangeSets();

        if ([] === $this->insertions && [] === $this->updates && [] === $this->deletions && [] === $this->collectionWrites && [] === $this->collectionUpdates && [] === $this->orphans && [] === $this->extraUpdates) {
            $this->dispatch(new Lifecycle\OnFlush($this));
            $this->dispatch(new Lifecycle\PostFlush($this));

            return;
        }

        foreach ($this->notCascaded as $oid => [$class, $property, $entity]) {
            if (!isset($this->insertions[$oid]) && !$this->store->identityMap()->contains($entity)) {
                throw InvalidPersistence::notCascaded($class, $property, $entity);
            }
        }
        foreach ($this->orphans as $orphan) {
            $this->remove($orphan);
        }

        $this->dispatch(new Lifecycle\OnFlush($this));

        $connection = $this->store->connection();
        $connection->beginTransaction();
        try {
            if ([] !== $this->insertions) {
                $this->executeInserts();
            }
            if ([] !== $this->updates) {
                $this->executeUpdates();
            }
            foreach ($this->extraUpdates as $oid => [$entity, $changeSet]) {
                $this->persister->update($entity, $changeSet);
                $this->written[$oid] = true;
            }
            $this->extraUpdates = [];
            foreach ($this->collectionWrites as [$owner, $relation, $removed, $added, $all]) {
                $this->persister->writeJoinRows($owner, $relation, $removed, $added, $all);
            }
            $deleted = [] !== $this->deletions ? $this->executeDeletions() : [];
            $connection->commit();
        } catch (\Throwable $failure) {
            $this->closed = true;
            $connection->rollBack();

            throw $failure;
        }

        $this->afterCommit($deleted);
        $this->dispatch(new Lifecycle\PostFlush($this));
    }

    private function computeChangeSets(): void
    {
        // The new entities first (and those they reach), then every managed entity not scheduled otherwise.
        for ($done = []; [] !== ($pending = array_diff_key($this->insertions, $done));) {
            foreach ($pending as $oid => $entity) {
                $done[$oid] = true;
                if (isset($this->insertions[$oid])) {
                    $this->computeChangeSet($entity);
                }
            }
        }
        foreach ($this->store->identityMap()->all() as $entity) {
            $oid = spl_object_id($entity);
            if (isset($this->insertions[$oid]) || isset($this->deletions[$oid]) || $this->store->isUninitialized($entity) || !$this->store->hasOriginal($entity)) {
                continue;
            }
            $this->computeChangeSet($entity);
        }
    }

    private function computeChangeSet(object $entity): void
    {
        $oid = spl_object_id($entity);
        $map = $this->store->mapping()->of($entity::class);
        $actual = $this->actual($map, $entity, true);

        if (isset($this->insertions[$oid])) {
            $changeSet = [];
            foreach ($actual as $property => $value) {
                $relation = $map->entity->relations[$property] ?? null;
                if (null === $relation || ($relation->isToOne() && $relation->owning)) {
                    $changeSet[$property] = [null, $value];
                }
                if (null === $relation || $relation->isToOne()) {
                    // What recompute() compares with, as Doctrine's original data of a new entity.
                    $this->store->setOriginal($entity, $property, $value);
                }
            }
            $this->changeSets[$oid] = $changeSet;
        } else {
            $original = $this->store->original($entity);
            $changeSet = [];
            foreach ($actual as $property => $value) {
                $relation = $map->entity->relations[$property] ?? null;
                if (null !== $relation && !$relation->isToOne()) {
                    // A collection read put out of the entity: in the change set, as Doctrine signals a de-referenced
                    // PersistentCollection there (its rows are rewritten with the join rows).
                    $read = $this->store->originalCollections($entity)[$property] ?? null;
                    if ($read instanceof LazyCollection && $read !== $value) {
                        $changeSet[$property] = [$read, $value];
                    }
                    continue;
                }
                if (!\array_key_exists($property, $original)) {
                    continue;
                }
                $before = $original[$property];
                if (self::same($before, $value)) {
                    continue;
                }
                if (null === $relation) {
                    $changeSet[$property] = [$before, $value];
                    continue;
                }
                if ($relation->owning) {
                    $changeSet[$property] = [$before, $value];
                }
                if (null !== $before && $this->cascades->orphanRemoval($map->className(), $relation)) {
                    $this->orphans[spl_object_id($before)] = $before;
                }
            }
            if ([] !== $changeSet) {
                $this->changeSets[$oid] = $changeSet;
                $this->updates[$oid] = $entity;
                foreach ($changeSet as $property => [, $after]) {
                    if (isset($map->entity->fields[$property]) || $map->entity->relations[$property]->isToOne()) {
                        $this->store->setOriginal($entity, $property, $after);
                    }
                }
            }
        }

        foreach ($map->entity->relations as $property => $relation) {
            $value = $this->store->value($entity, $property);
            if (null === $value) {
                continue;
            }
            $this->computeAssociationChanges($entity, $map, $relation, $value);
        }
    }

    /**
     * An association's changes: a collection's elements against its snapshot (the join rows of an owning
     * many-to-many, the orphans of an orphan-removing one), and the new entities it reaches - persisted when the
     * association cascades the persist, remembered otherwise (refused at the flush unless persisted another way).
     */
    private function computeAssociationChanges(object $entity, EntityMap $map, Relation $relation, mixed $value): void
    {
        $oid = spl_object_id($entity);
        if ($relation->isToOne()) {
            if (!\is_object($value) || $this->store->isUninitialized($value)) {
                return;
            }
            $entries = [$value];
        } else {
            if (!$value instanceof Collection && !\is_array($value)) {
                throw InvalidPersistence::invalidAssociation($map->className(), $relation->property, $value);
            }
            $originalCollection = $this->store->originalCollections($entity)[$relation->property] ?? null;
            // A collection never read, the one the store gave: unchanged, and holding no new entity.
            $untouched = $value instanceof LazyCollection && !$value->isInitialized() && !$value->isDirty() && $originalCollection === $value;
            $entries = $untouched ? [] : ($value instanceof Collection ? $value->toArray() : $value);
            // Doctrine's scheduled collection updates: a collection changed since it was read (PersistentCollection's
            // isDirty()), or one the application made that holds an element (Doctrine wraps it, dirty unless empty).
            if ($value instanceof LazyCollection ? $value->isDirty() : [] !== $entries) {
                $this->collectionUpdates[$oid.'#'.$relation->property] = [$entity, $relation->property, $value];
            }
            $owningManyToMany = Relation::MANY_TO_MANY === $relation->kind && $relation->owning;
            $orphanRemoval = $this->cascades->orphanRemoval($map->className(), $relation);
            if (!$untouched && ($owningManyToMany || $orphanRemoval)) {
                [$removed, $added, $all] = $this->collectionDiff($value, $originalCollection, isset($this->insertions[$oid]), $entries);
                if ($orphanRemoval) {
                    foreach ($removed as $orphan) {
                        $this->orphans[spl_object_id($orphan)] = $orphan;
                    }
                }
                if ($owningManyToMany && ([] !== $removed || [] !== $added || $all)) {
                    $this->collectionWrites[$oid.'#'.$relation->property] = [$entity, $relation, $all ? [] : $removed, $added, $all];
                }
                // A changed owning many-to-many updates its owner with an empty change set - its PreUpdate runs -, as
                // Doctrine does for a dirty PersistentCollection: even one holding what it held (cleared and filled
                // again), not one only cleared (Doctrine's clear() takes its snapshot: no longer dirty).
                $dirty = $value instanceof LazyCollection ? $value->isDirty() : ([] !== $removed || [] !== $added || $all);
                if ($owningManyToMany && $dirty && !isset($this->changeSets[$oid]) && !isset($this->insertions[$oid])) {
                    $this->changeSets[$oid] = [];
                    $this->updates[$oid] = $entity;
                }
            }
        }

        $target = $this->store->mapping()->of($relation->target);
        foreach ($entries as $entry) {
            if (!$entry instanceof ($target->className())) {
                throw InvalidPersistence::invalidAssociation($map->className(), $relation->property, $entry);
            }
            $entryOid = spl_object_id($entry);
            if (isset($this->deletions[$entryOid])) {
                if (!$relation->isToOne()) {
                    $this->visited[$oid.'#'.$relation->property] = [$entity, $relation->property];
                }
                continue;
            }
            if ($this->contains($entry)) {
                continue;
            }
            if (!$this->cascades->cascades($map->className(), $relation, 'persist')) {
                $this->notCascaded[$entryOid] = [$map->className(), $relation->property, $entry];
                continue;
            }
            $this->persistNew($entry);
            $this->computeChangeSet($entry);
        }
    }

    /**
     * A collection's elements taken out and added since its snapshot: [removed, added, whether every row of the
     * owner goes first] - a collection put in place of the one read replaces it whole, as Doctrine deletes a
     * de-referenced PersistentCollection's rows and inserts the new one's.
     *
     * @param list<object>|array<array-key, object> $entries
     *
     * @return array{0: list<object>, 1: list<object>, 2: bool}
     */
    private function collectionDiff(mixed $value, mixed $original, bool $new, array $entries): array
    {
        $entries = array_values(array_filter($entries, 'is_object'));
        if ($new || null === $original) {
            return [[], $entries, false];
        }
        if ($value === $original && $value instanceof LazyCollection) {
            $before = array_values($value->snapshot());

            // An owning collection cleared since it was read: every row of the owner goes first (Doctrine's collection
            // deletion), its snapshot emptied then.
            return [self::minus($before, $entries), self::minus($entries, $before), $value->wasCleared()];
        }
        $before = $original instanceof LazyCollection || $original instanceof Collection ? array_values($original->toArray()) : (\is_array($original) ? array_values($original) : []);

        return [self::minus($before, $entries), $entries, true];
    }

    /**
     * @param list<object> $from
     * @param list<object> $without
     *
     * @return list<object>
     */
    private static function minus(array $from, array $without): array
    {
        $ids = [];
        foreach ($without as $entity) {
            $ids[spl_object_id($entity)] = true;
        }

        return array_values(array_filter($from, static fn (object $entity): bool => !isset($ids[spl_object_id($entity)])));
    }

    private function executeInserts(): void
    {
        $order = new CommitOrder();
        foreach ($this->insertions as $entity) {
            $order->add($entity);
        }
        foreach ($this->insertions as $entity) {
            $map = $this->store->mapping()->of($entity::class);
            foreach ($map->entity->relations as $property => $relation) {
                if (!$relation->isToOne() || !$relation->owning) {
                    continue;
                }
                $target = $this->store->value($entity, $property);
                if (null === $target || !$order->has($target)) {
                    continue;
                }
                if ($target === $entity && !Persister::generated($map)) {
                    continue;
                }
                $order->depends($entity, $target, $relation->joinColumns[0]->nullable ?? true);
            }
        }
        try {
            $sorted = $order->sort();
        } catch (CycleFound $cycle) {
            throw InvalidPersistence::cycle($cycle->cycle);
        }

        $inserted = [];
        foreach ($sorted as $entity) {
            $oid = spl_object_id($entity);
            if (!isset($this->changeSets[$oid])) {
                $this->computeChangeSet($entity);
            }
            unset($this->insertions[$oid]);
            [$generated, $extra] = $this->persister->insert($entity, $this->changeSets[$oid] ?? [], fn (object $target): bool => isset($this->insertions[spl_object_id($target)]));
            $map = $this->store->mapping()->of($entity::class);
            if (null !== $generated) {
                $this->store->setValue($entity, $map->identifier[0]->property, $generated);
                $this->store->register($entity, $generated);
            }
            if ([] !== $extra) {
                $this->scheduleExtraUpdate($entity, $extra);
            }
            $this->computed[$oid] = $this->store->original($entity);
            $this->written[$oid] = true;
            $inserted[] = $entity;
        }
        foreach ($inserted as $entity) {
            $this->dispatch(new Lifecycle\PostPersist($entity, $this));
        }
    }

    private function executeUpdates(): void
    {
        $entities = array_values($this->updates);
        usort($entities, function (object $a, object $b): int {
            return strcmp($a::class, $b::class) ?: strcmp($this->idHash($a), $this->idHash($b));
        });
        foreach ($entities as $entity) {
            $oid = spl_object_id($entity);
            $given = $this->changeSets[$oid] ?? [];
            $event = new Lifecycle\PreUpdate($entity, $this, $given);
            $this->dispatch($event);
            foreach ($event->changes() as $property => [, $after]) {
                if (\array_key_exists($property, $this->changeSets[$oid] ?? []) && !self::same($given[$property][1] ?? null, $after)) {
                    $this->changeSets[$oid][$property][1] = $after;
                }
            }
            $this->recompute($entity);
            if ([] !== ($this->changeSets[$oid] ?? [])) {
                $this->persister->update($entity, $this->changeSets[$oid]);
            }
            unset($this->updates[$oid]);
            $this->computed[$oid] = $this->store->original($entity);
            $this->written[$oid] = true;
            $this->dispatch(new Lifecycle\PostUpdate($entity, $this));
        }
    }

    /** @return list<object> the entities deleted */
    private function executeDeletions(): array
    {
        $order = new CommitOrder();
        foreach ($this->deletions as $entity) {
            $order->add($entity);
        }
        foreach ($this->deletions as $entity) {
            $map = $this->store->mapping()->of($entity::class);
            foreach ($map->entity->relations as $property => $relation) {
                if (!$relation->isToOne() || !$relation->owning) {
                    continue;
                }
                if ('set null' === strtolower((string) ($relation->joinColumns[0]->onDelete ?? ''))) {
                    continue;
                }
                $target = $this->store->value($entity, $property);
                if (null === $target || $target === $entity || !$order->has($target)) {
                    continue;
                }
                // The target is deleted after the entity pointing to it.
                $order->depends($target, $entity, false);
            }
        }
        try {
            $sorted = $order->sort();
        } catch (CycleFound $cycle) {
            throw InvalidPersistence::cycle($cycle->cycle);
        }

        foreach ($sorted as $entity) {
            $map = $this->store->mapping()->of($entity::class);
            $this->persister->delete($entity);
            unset($this->deletions[spl_object_id($entity)]);
            $this->store->forget($entity);
            if (Persister::generated($map)) {
                $field = $map->identifier[0];
                $type = (new \ReflectionProperty($field->declaredIn ?? $map->className(), $field->property))->getType();
                if (null === $type || $type->allowsNull()) {
                    $this->store->setValue($entity, $field->property, null);
                }
            }
        }
        foreach ($sorted as $entity) {
            $this->dispatch(new Lifecycle\PostRemove($entity, $this));
        }

        return $sorted;
    }

    /**
     * After the commit: what was written becomes the snapshot - a collection the application made becomes a
     * LazyCollection holding the same elements -, and the deleted entities leave the collections that held them.
     *
     * @param list<object> $deleted
     */
    private function afterCommit(array $deleted): void
    {
        $gone = [];
        foreach ($deleted as $entity) {
            $gone[spl_object_id($entity)] = true;
        }
        foreach ($this->visited as [$owner, $property]) {
            $collection = $this->store->value($owner, $property);
            if ($collection instanceof Collection) {
                foreach ($collection->toArray() as $key => $element) {
                    if (\is_object($element) && isset($gone[spl_object_id($element)])) {
                        $collection->remove($key);
                    }
                }
            }
        }
        foreach ($this->store->identityMap()->all() as $entity) {
            $oid = spl_object_id($entity);
            if ($this->store->isUninitialized($entity)) {
                continue;
            }
            $map = $this->store->mapping()->of($entity::class);
            foreach ($map->entity->relations as $property => $relation) {
                if ($relation->isToOne()) {
                    continue;
                }
                $value = $this->store->value($entity, $property);
                if ($value instanceof LazyCollection) {
                    $value->takeSnapshot();
                } elseif ($value instanceof Collection || \is_array($value)) {
                    $elements = $value instanceof Collection ? $value->toArray() : $value;
                    $collection = LazyCollection::of($elements, Relation::MANY_TO_MANY === $relation->kind && $relation->owning);
                    if ($value instanceof Collection) {
                        $this->store->setValue($entity, $property, $collection);
                    }
                    $this->written[$oid] = true;
                }
            }
            if (isset($this->written[$oid])) {
                $this->store->snapshot($entity);
                // Its fields and to-one associations as the change set took them, not as the Post* listeners left
                // them (Doctrine's original data); its identifier the database's.
                foreach ($this->computed[$oid] ?? [] as $property => $value) {
                    $this->store->setOriginal($entity, $property, $value);
                }
            }
        }
        $this->computed = [];
    }

    /**
     * The values a change set compares: each field (an identifier the database gives left out, as Doctrine leaves
     * it), each to-one association, and - for the associations' changes - each to-many collection.
     *
     * @return array<string, mixed>
     */
    private function actual(EntityMap $map, object $entity, bool $collections): array
    {
        $generated = Persister::generated($map) ? $map->identifier[0]->property : null;
        $actual = [];
        foreach ($map->entity->fields as $property => $field) {
            if ($property !== $generated) {
                $actual[$property] = $this->store->value($entity, $property);
            }
        }
        foreach ($map->entity->relations as $property => $relation) {
            if ($relation->isToOne() || $collections) {
                $actual[$property] = $this->store->value($entity, $property);
            }
        }

        return $actual;
    }

    /** Doctrine's comparison: strict, an object by identity, an enumeration by its value. */
    private static function same(mixed $before, mixed $after): bool
    {
        return self::scalar($before) === self::scalar($after);
    }

    private static function scalar(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if (\is_array($value)) {
            foreach ($value as $key => $member) {
                if ($member instanceof \BackedEnum) {
                    $value[$key] = $member->value;
                }
            }
        }

        return $value;
    }

    private function idHash(object $entity): string
    {
        $id = $this->store->identifier($entity);

        return implode(' ', array_map(static fn (mixed $value): string => IdentityMap::key($value), \is_array($id) ? $id : [$id]));
    }

    private function dispatch(object $event): void
    {
        foreach ($this->listeners as $listener) {
            $listener($event);
        }
        $this->dispatcher?->dispatch($event);
    }

    private function open(): void
    {
        if ($this->closed) {
            throw UnsupportedPersistence::closed();
        }
    }

    private function cleanup(): void
    {
        $this->insertions = $this->closed ? [] : $this->insertions;
        $this->updates = [];
        $this->deletions = $this->closed ? [] : $this->deletions;
        $this->changeSets = [];
        $this->extraUpdates = [];
        $this->collectionWrites = [];
        $this->orphans = [];
        $this->collectionUpdates = [];
        $this->notCascaded = [];
        $this->visited = [];
        $this->written = [];
        $this->computed = [];
    }
}
