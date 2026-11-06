<?php

namespace Omnibridge\Eloquent\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork as DoctrineUnitOfWorkState;
use Omnibridge\Persistence\UnitOfWork;

/**
 * omnisense/omnibridge's UnitOfWork straight over a Doctrine entity manager, with nothing of omnibase's (the
 * reference the write parity compares omnibridge/eloquent with): what omnibridge/doctrine's DoctrineUnitOfWork
 * answers, in a few lines.
 */
final class DoctrineSide implements UnitOfWork
{
    public function __construct(public readonly EntityManagerInterface $entityManager)
    {
    }

    public function persist(object $entity): void
    {
        $this->entityManager->persist($entity);
    }

    public function remove(object $entity): void
    {
        $this->entityManager->remove($entity);
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }

    public function refresh(object $entity): void
    {
        $this->entityManager->refresh($entity);
    }

    public function contains(object $entity): bool
    {
        return $this->entityManager->contains($entity);
    }

    public function reference(string $class, mixed $id): object
    {
        return $this->entityManager->getReference($class, $id);
    }

    public function transactional(callable $work): mixed
    {
        return $this->entityManager->wrapInTransaction(fn () => $work($this));
    }

    public function isNew(object $entity): bool
    {
        $unitOfWork = $this->entityManager->getUnitOfWork();

        return $unitOfWork->isScheduledForInsert($entity) || DoctrineUnitOfWorkState::STATE_NEW === $unitOfWork->getEntityState($entity, DoctrineUnitOfWorkState::STATE_NEW);
    }

    public function identifier(object $entity): mixed
    {
        $values = $this->entityManager->getClassMetadata($entity::class)->getIdentifierValues($entity);

        return match (\count($values)) {
            0 => null,
            1 => reset($values),
            default => $values,
        };
    }

    public function className(object $entity): string
    {
        return $this->entityManager->getClassMetadata($entity::class)->getName();
    }

    public function changes(object $entity): array
    {
        return $this->entityManager->getUnitOfWork()->getEntityChangeSet($entity);
    }

    public function recompute(object $entity): void
    {
        $unitOfWork = $this->entityManager->getUnitOfWork();
        $metadata = $this->entityManager->getClassMetadata($entity::class);
        if ($unitOfWork->isScheduledForInsert($entity) && [] === $unitOfWork->getOriginalEntityData($entity)) {
            $unitOfWork->computeChangeSet($metadata, $entity);

            return;
        }
        $unitOfWork->recomputeSingleEntityChangeSet($metadata, $entity);
    }
}
