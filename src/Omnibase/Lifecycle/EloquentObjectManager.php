<?php

namespace Omnibridge\Eloquent\Omnibase\Lifecycle;

use Doctrine\Persistence\Mapping\ClassMetadata;
use Doctrine\Persistence\Mapping\ClassMetadataFactory;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use Omnibridge\Eloquent\EloquentUnitOfWork;
use Omnibridge\Eloquent\UnsupportedPersistence;

/**
 * doctrine/persistence's ObjectManager over omnibridge/eloquent's unit of work (plan "omnibase agnostic", step F7c): what
 * the lifecycle arguments of omnibase's entity dispatchers carry (their subclasses type their hooks on
 * Doctrine\Persistence\Event\LifecycleEventArgs: lesringostars' NewsletterEventDispatcher). The unit of work's
 * operations; Doctrine's metadata and repositories are refused aloud (there are none: the description and
 * omnisense/omnibase's Repositories stand in their place).
 */
final class EloquentObjectManager implements ObjectManager
{
    public function __construct(private readonly EloquentUnitOfWork $unitOfWork)
    {
    }

    public function unitOfWork(): EloquentUnitOfWork
    {
        return $this->unitOfWork;
    }

    public function find(string $className, mixed $id): ?object
    {
        return $this->unitOfWork->store()->find($className, $id);
    }

    public function persist(object $object): void
    {
        $this->unitOfWork->persist($object);
    }

    public function remove(object $object): void
    {
        $this->unitOfWork->remove($object);
    }

    public function clear(): void
    {
        $this->unitOfWork->store()->clear();
    }

    public function detach(object $object): void
    {
        $this->unitOfWork->detach($object);
    }

    public function refresh(object $object): void
    {
        $this->unitOfWork->refresh($object);
    }

    public function flush(): void
    {
        $this->unitOfWork->flush();
    }

    public function getRepository(string $className): ObjectRepository
    {
        throw UnsupportedPersistence::operation('give doctrine/persistence\'s repositories', 'omnisense/omnibase\'s Repositories give its entities\' (Base\Database\UnitOfWorks::repositories())');
    }

    public function getClassMetadata(string $className): ClassMetadata
    {
        throw UnsupportedPersistence::operation('give Doctrine\'s class metadata', 'the entities are described by omnisense/omnibridge\'s Description (their shapes: Base\Database\UnitOfWorks::shapes())');
    }

    public function getMetadataFactory(): ClassMetadataFactory
    {
        throw UnsupportedPersistence::operation('give Doctrine\'s metadata factory', 'the entities are described by omnisense/omnibridge\'s Description');
    }

    public function initializeObject(object $obj): void
    {
        $reflection = new \ReflectionClass($obj);
        if ($reflection->isUninitializedLazyObject($obj)) {
            $reflection->initializeLazyObject($obj);
        }
    }

    public function isUninitializedObject(mixed $value): bool
    {
        return \is_object($value) && $this->unitOfWork->isUninitializedObject($value);
    }

    public function contains(object $object): bool
    {
        return $this->unitOfWork->contains($object);
    }
}
