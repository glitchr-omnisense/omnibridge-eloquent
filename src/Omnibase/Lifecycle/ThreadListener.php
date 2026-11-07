<?php

namespace Omnibridge\Eloquent\Omnibase\Lifecycle;

use Base\Database\Lifecycle\ThreadParticipants;
use Base\Entity\Thread;
use Base\Entity\ThreadIntl;
use Omnibridge\Eloquent\EloquentUnitOfWork;
use Omnibridge\Persistence\Lifecycle;

/**
 * A thread's participants on omnibridge/eloquent (plan "omnibase agnostic", step F7c): omnisense/omnibase's
 * ThreadSubscriber's Doctrine hooks, the rule the core's (ThreadParticipants). A new reply followed by its parent's
 * owners (PrePersist); at OnFlush, the mentions of every thread whose text the flush writes or that it makes
 * publishable, and the followers of a reply moved under another parent (its change set computed again with its
 * collections).
 */
final class ThreadListener
{
    private ?ThreadParticipants $participants = null;

    /** @param \Closure(): \Base\Service\Model\Wysiwyg\MentionEnhancerInterface $mentionEnhancer */
    public function __construct(private readonly \Closure $mentionEnhancer)
    {
    }

    private ?EloquentUnitOfWork $unitOfWork = null;

    private function participants(): ThreadParticipants
    {
        // The mentionees as the store holds them (StoreMentionees): the Mentions the flush writes point to them.
        return $this->participants ??= new ThreadParticipants(fn () => null === $this->unitOfWork ? ($this->mentionEnhancer)() : new StoreMentionees(($this->mentionEnhancer)(), $this->unitOfWork->store()));
    }

    public function __invoke(object $event): void
    {
        if ($event->unitOfWork instanceof EloquentUnitOfWork) {
            $this->unitOfWork = $event->unitOfWork;
        }
        if ($event instanceof Lifecycle\PrePersist) {
            $this->participants()->onPrePersist($event);
        } elseif ($event instanceof Lifecycle\OnFlush && $event->unitOfWork instanceof EloquentUnitOfWork) {
            $this->onFlush($event, $event->unitOfWork);
        }
    }

    private function onFlush(Lifecycle\OnFlush $event, EloquentUnitOfWork $unitOfWork): void
    {
        $threads = [];
        foreach ($unitOfWork->scheduledInsertions() as $entity) {
            if ($entity instanceof Thread) {
                $threads[spl_object_id($entity)] = $entity;
            } elseif ($entity instanceof ThreadIntl && $entity->getTranslatable() instanceof Thread) {
                $threads[spl_object_id($entity->getTranslatable())] = $entity->getTranslatable();
            }
        }

        foreach ($unitOfWork->scheduledUpdates() as $entity) {
            if ($entity instanceof ThreadIntl && $entity->getTranslatable() instanceof Thread && \array_key_exists('content', $unitOfWork->changes($entity))) {
                $threads[spl_object_id($entity->getTranslatable())] = $entity->getTranslatable();
            }
            if ($entity instanceof Thread && $entity->isPublishable()) {
                $threads[spl_object_id($entity)] = $entity;
            }
            if ($entity instanceof Thread && \array_key_exists('parent', $unitOfWork->changes($entity)) && $this->participants()->follow($entity)) {
                $unitOfWork->recomputeWithAssociations($entity);
            }
        }

        $threads = array_filter($threads, static fn (Thread $thread) => !$unitOfWork->isScheduledForDelete($thread));
        if ($threads) {
            $this->participants()->onFlush($event, $threads);
        }
    }
}
