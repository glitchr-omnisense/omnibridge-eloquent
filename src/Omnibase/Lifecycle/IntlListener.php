<?php

namespace Omnibridge\Eloquent\Omnibase\Lifecycle;

use Base\Database\Entity\Extension\TranslatableInterface;
use Base\Database\Entity\Extension\TranslationInterface;
use Omnibridge\Eloquent\EloquentUnitOfWork;
use Omnibridge\Persistence\Lifecycle;

/**
 * omnibase's translations on omnibridge/eloquent (plan "omnibase agnostic", step F7c): what omnibridge/doctrine's
 * IntlSubscriber does at the flush and the reads (its mapping part - the translations' association, cascades, unique
 * locale - is the description's and OmnibaseCascades' here; its query part, TranslatableWalker, the finder's join).
 *
 * - PostLoad: an empty translation read is removed as an orphan at the next flush.
 * - PrePersist and PreFlush: the pending translations of a translatable (new, or every one the unit of work holds)
 *   committed into its collection.
 * - OnFlush: each translation and translatable the flush writes (and each owner of a collection it finds changed)
 *   made consistent - an empty new translation dropped (detached), a locale given from its key, the translatable
 *   set; an empty translation the database holds deleted, a non-empty one's orphan removal taken back.
 */
final class IntlListener
{
    public function __invoke(object $event): void
    {
        if (!$event->unitOfWork instanceof EloquentUnitOfWork) {
            return;
        }
        match (true) {
            $event instanceof Lifecycle\PostLoad => $this->postLoad($event),
            $event instanceof Lifecycle\PrePersist => $this->prePersist($event),
            $event instanceof Lifecycle\PreFlush => $this->preFlush($event->unitOfWork),
            $event instanceof Lifecycle\OnFlush => $this->onFlush($event->unitOfWork),
            default => null,
        };
    }

    private function postLoad(Lifecycle\PostLoad $event): void
    {
        $object = $event->entity;
        if ($object instanceof TranslationInterface && $object->isEmpty()) {
            $event->unitOfWork->scheduleOrphanRemoval($object);
        }
    }

    private function prePersist(Lifecycle\PrePersist $event): void
    {
        $entity = $event->entity;
        if ($entity instanceof TranslatableInterface && method_exists($entity, 'commitPendingTranslations')) {
            $entity->commitPendingTranslations();
        }
    }

    private function preFlush(EloquentUnitOfWork $unitOfWork): void
    {
        $entities = [...$unitOfWork->scheduledInsertions(), ...$unitOfWork->store()->identityMap()->all()];
        foreach ($entities as $entity) {
            if (!$entity instanceof TranslatableInterface || !method_exists($entity, 'commitPendingTranslations')) {
                continue;
            }
            if ($unitOfWork->isUninitializedObject($entity)) {
                continue;
            }
            $entity->commitPendingTranslations();
        }
    }

    private function onFlush(EloquentUnitOfWork $unitOfWork): void
    {
        $scheduled = [...$unitOfWork->scheduledInsertions(), ...$unitOfWork->scheduledUpdates()];
        foreach ($unitOfWork->scheduledCollectionUpdates() as [$owner]) {
            $scheduled[] = $owner;
        }
        $scheduled = array_filter(array_unique_object($scheduled), static fn ($entity) => $entity instanceof TranslationInterface || $entity instanceof TranslatableInterface);
        foreach (array_unique_object($scheduled) as $entity) {
            $this->normalize($unitOfWork, $entity);
        }
    }

    private function normalize(EloquentUnitOfWork $unitOfWork, TranslationInterface|TranslatableInterface $entity): void
    {
        if ($entity instanceof TranslatableInterface) {
            foreach ($entity->getTranslations() as $locale => $translation) {
                if ($translation->isEmpty() && !$translation->getId()) {
                    $entity->removeTranslation($translation);
                    if ($unitOfWork->contains($translation)) {
                        $unitOfWork->detach($translation);
                    }
                    continue;
                }
                if (null === $translation->getLocale()) {
                    $translation->setLocale($locale);
                }
                if (null !== $translation->getLocale() && $translation->getLocale() !== $translation->getLocale($locale)) {
                    throw new \InvalidArgumentException('Unexpected locale "'.$translation->getLocale().'" found with respect to collection key "'.$locale.'".');
                }
                if (!$translation->getTranslatable()) {
                    $translation->setTranslatable($entity);
                }
            }
        }

        if ($entity instanceof TranslationInterface) {
            if ($entity->isEmpty()) {
                $translatable = $entity->getTranslatable();
                if ($translatable) {
                    $translatable->removeTranslation($entity);
                }
                if ($entity->getId()) {
                    $unitOfWork->scheduleForDelete($entity);
                } else {
                    $unitOfWork->detach($entity);
                }
            } else {
                $unitOfWork->cancelOrphanRemoval($entity);
            }
        }
    }
}
