<?php

namespace Omnibridge\Eloquent\Omnibase\Lifecycle;

use Base\Database\Attribute\Trasheable;
use Base\Database\Lifecycle\TrashRecorder;
use DateTime;
use DateTimeInterface;
use Omnibridge\Eloquent\EloquentUnitOfWork;
use Omnibridge\Eloquent\EntityMap;
use Omnibridge\Persistence\Lifecycle;

/**
 * omnibase's soft delete on omnibridge/eloquent (plan "omnibase agnostic", step F7c): omnibridge/doctrine's
 * TrasheableSubscriber, on the unit of work's OnFlush, the rule the core's (TrashRecorder).
 *
 * For each #[Trasheable] entity the flush deletes whose deletion field is not stamped yet (an entity stamped already
 * is deleted for real: emptying the trash): the field stamped now, the deletion taken back (persist(), which takes
 * back what it cascades to), the stamp and the entity's other pending changes written by an extra update (no
 * PreUpdate), the entities the removal cascaded to taken back with it, and the trash entry inserted in the same flush.
 * Nothing to evict afterwards: the store keeps no second-level cache.
 */
final class TrasheableListener
{
    private readonly TrashRecorder $recorder;

    /** @param string|int $emptyTrash base.extension.empty_trash: a relative date ("+7 days"), or a number of days */
    public function __construct(string|int $emptyTrash = '+7 days')
    {
        $this->recorder = new TrashRecorder($emptyTrash);
    }

    public function __invoke(object $event): void
    {
        if ($event instanceof Lifecycle\OnFlush && $event->unitOfWork instanceof EloquentUnitOfWork) {
            $this->onFlush($event->unitOfWork);
        }
    }

    private function onFlush(EloquentUnitOfWork $unitOfWork): void
    {
        $store = $unitOfWork->store();
        foreach ($unitOfWork->scheduledDeletions() as $entity) {
            $trasheable = Trasheable::resolve($entity::class);
            if (!$trasheable) {
                continue;
            }

            $field = $trasheable->deletedAt;
            $map = $store->mapping()->of($entity::class);
            if (!isset($map->entity->fields[$field])) {
                continue;
            }

            $oldValue = $store->value($entity, $field);
            if ($this->recorder->isPurge($oldValue)) {
                continue;
            }

            $deletedAt = new DateTime();
            $store->setValue($entity, $field, $deletedAt);

            $unitOfWork->persist($entity);
            $unitOfWork->propertyChanged($entity, $field, $oldValue, $deletedAt);
            $unitOfWork->scheduleExtraUpdate($entity, $this->withPendingChanges($unitOfWork, $map, $entity, [$field => [$oldValue, $deletedAt]]));

            if ($trasheable->cascade) {
                $this->rescueCascade($unitOfWork, $entity, [spl_object_id($entity) => true]);
            }

            $trashBall = $this->recorder->entry($entity, $map->className(), $trasheable, $deletedAt);
            $unitOfWork->persist($trashBall);
            $trashBall->setCreatedAt($deletedAt);
            $unitOfWork->recompute($trashBall);
        }
    }

    /**
     * The deletion stamp, plus what else changed on the entity since it was read (fields and owning to-one
     * associations), the remembered values moved forward: an entity scheduled for deletion has no change set of its
     * own (TrasheableSubscriber::withPendingChanges()).
     *
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private function withPendingChanges(EloquentUnitOfWork $unitOfWork, EntityMap $map, object $entity, array $changeSet): array
    {
        $store = $unitOfWork->store();
        $original = $store->original($entity);
        $identifier = array_map(static fn ($field): string => $field->property, $map->identifier);

        foreach ($map->entity->fields as $name => $_) {
            if (isset($changeSet[$name]) || \in_array($name, $identifier, true) || !\array_key_exists($name, $original)) {
                continue;
            }
            $old = $original[$name];
            $new = $store->value($entity, $name);
            if ($old === $new || ($old instanceof DateTimeInterface && $new instanceof DateTimeInterface && $old == $new)) {
                continue;
            }
            $changeSet[$name] = [$old, $new];
            $unitOfWork->setOriginal($entity, $name, $new);
        }

        foreach ($map->entity->relations as $name => $relation) {
            if (isset($changeSet[$name]) || !$relation->isToOne() || !$relation->owning || !\array_key_exists($name, $original)) {
                continue;
            }
            $new = $store->value($entity, $name);
            if ($original[$name] === $new) {
                continue;
            }
            $changeSet[$name] = [$original[$name], $new];
            $unitOfWork->setOriginal($entity, $name, $new);
        }

        return $changeSet;
    }

    /**
     * Every entity this removal cascaded to (a cascaded removal or an orphan removal) taken back, recursively.
     *
     * @param array<int, bool> $seen
     */
    private function rescueCascade(EloquentUnitOfWork $unitOfWork, object $entity, array $seen): void
    {
        $store = $unitOfWork->store();
        $map = $store->mapping()->of($entity::class);
        foreach ($map->entity->relations as $property => $relation) {
            if (!$unitOfWork->cascades()->cascades($map->className(), $relation, 'remove') && !$unitOfWork->cascades()->orphanRemoval($map->className(), $relation)) {
                continue;
            }
            $value = $store->value($entity, $property);
            if (null === $value) {
                continue;
            }
            foreach (is_iterable($value) ? $value : [$value] as $child) {
                if (!\is_object($child)) {
                    continue;
                }
                $id = spl_object_id($child);
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                if (!$unitOfWork->isScheduledForDelete($child)) {
                    continue;
                }
                $unitOfWork->persist($child);
                $this->rescueCascade($unitOfWork, $child, $seen);
            }
        }
    }
}
