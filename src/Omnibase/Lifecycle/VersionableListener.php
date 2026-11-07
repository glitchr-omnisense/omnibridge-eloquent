<?php

namespace Omnibridge\Eloquent\Omnibase\Lifecycle;

use Base\Database\Attribute\Versionable;
use Base\Database\Lifecycle\RevisionRecorder;
use Base\Entity\Extension\Revision;
use DateTime;
use Omnibridge\Eloquent\EloquentUnitOfWork;
use Omnibridge\Eloquent\LazyCollection;
use Omnibridge\Eloquent\Omnibase\Behaviour\EloquentBulkDeletes;
use Omnibridge\Persistence\Lifecycle;

/**
 * omnibase's revisions on omnibridge/eloquent (plan "omnibase agnostic", step F7c): omnibridge/doctrine's
 * VersionableSubscriber, the rule the core's (RevisionRecorder). At OnFlush, each #[Versionable] entity the flush
 * updates, or whose collection it finds changed, gives its anchor (the entity itself, or the translatable of a
 * translation) one Revision of what changed - its versioned fields' changes and its versioned collections' elements
 * before and after -, inserted in the same flush. After the flush, the revisions beyond the most recent ones kept
 * (base.extension.max_revisions) are deleted, in statements.
 */
final class VersionableListener
{
    /** @var array<int, Revision> */
    private array $pending = [];

    private ?RevisionRecorder $recorder = null;

    private ?EloquentUnitOfWork $unitOfWork = null;

    public function __construct(private readonly int $maxRevisions = 5)
    {
    }

    public function __invoke(object $event): void
    {
        if (!($event instanceof Lifecycle\FlushEvent) || !$event->unitOfWork instanceof EloquentUnitOfWork) {
            return;
        }
        if ($event instanceof Lifecycle\OnFlush) {
            $this->onFlush($event->unitOfWork);
        } elseif ($event instanceof Lifecycle\PostFlush) {
            $this->postFlush($event->unitOfWork);
        }
    }

    private function recorder(EloquentUnitOfWork $unitOfWork): RevisionRecorder
    {
        if ($this->unitOfWork !== $unitOfWork) {
            $this->unitOfWork = $unitOfWork;
            $this->recorder = new RevisionRecorder($unitOfWork);
        }

        return $this->recorder;
    }

    private function onFlush(EloquentUnitOfWork $unitOfWork): void
    {
        $diffs = [];
        $candidates = $unitOfWork->scheduledUpdates();
        foreach ($unitOfWork->scheduledCollectionUpdates() as [$owner]) {
            $candidates[] = $owner;
        }

        foreach ($candidates as $entity) {
            $versioned = Versionable::resolve($entity::class);
            if (empty($versioned)) {
                continue;
            }
            [$target, $prefix] = Versionable::anchor($entity);
            if (null === $target || null === $target->getId()) {
                continue;
            }
            $diff = $this->diff($unitOfWork, $entity, $versioned, $prefix);
            if (empty($diff)) {
                continue;
            }
            $id = spl_object_id($target);
            $diffs[$id] ??= [$target, []];
            $diffs[$id][1] = array_merge($diffs[$id][1], $diff);
        }

        foreach ($diffs as [$target, $diff]) {
            $revision = $this->recorder($unitOfWork)->revision($target::class, $target, $diff);
            if (null === $revision) {
                continue;
            }
            $unitOfWork->persist($revision);
            $revision->setCreatedAt(new DateTime());
            $unitOfWork->recompute($revision);
            $this->pending[spl_object_id($revision)] = $revision;
        }
    }

    private function postFlush(EloquentUnitOfWork $unitOfWork): void
    {
        if (empty($this->pending)) {
            return;
        }
        $revisions = $this->pending;
        $this->pending = [];

        $repositories = \Base\Database\UnitOfWorks::repositories($unitOfWork);
        $trimmed = [];
        foreach ($revisions as $revision) {
            $key = $revision->getEntityClass().'#'.$revision->getEntityId();
            if (isset($trimmed[$key]) || null === $revision->getEntityId()) {
                continue;
            }
            $trimmed[$key] = true;

            $obsolete = $repositories->of(Revision::class)->obsoleteIds($revision->getEntityClass(), $revision->getEntityId(), $this->maxRevisions);
            if (empty($obsolete)) {
                continue;
            }
            (new EloquentBulkDeletes($unitOfWork))->deleteIds(Revision::class, $obsolete);
        }
    }

    /** @param array<string, mixed> $versioned */
    private function diff(EloquentUnitOfWork $unitOfWork, object $entity, array $versioned, string $prefix): array
    {
        $changeSet = $unitOfWork->changes($entity);
        $map = $unitOfWork->store()->mapping()->of($entity::class);

        $collections = [];
        foreach ($versioned as $property => $_) {
            if (\array_key_exists($property, $changeSet)) {
                continue;
            }
            $relation = $map->entity->relations[$property] ?? null;
            if (null === $relation || $relation->isToOne()) {
                continue;
            }
            $collection = $unitOfWork->store()->value($entity, $property);
            if (!$collection instanceof LazyCollection || !$collection->isDirty()) {
                continue;
            }
            $collections[$property] = [$collection->snapshot(), $collection->toArray()];
        }

        return $this->recorder($unitOfWork)->diff($versioned, $changeSet, $collections, $prefix);
    }
}
