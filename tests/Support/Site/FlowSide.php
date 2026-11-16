<?php

namespace Omnibridge\Eloquent\Tests\Support\Site;

use Base\Database\Repository\Entities;
use Base\Database\UnitOfWorks;
use Base\Repository\Aliases;
use Doctrine\ORM\EntityManagerInterface;
use Omnibridge\Eloquent\Omnibase\EloquentPersistence;
use Omnibridge\Persistence\UnitOfWork;
use Psr\Container\ContainerInterface;

/**
 * One backend of the flows compared at step F7c (third part, tests/Site/FlowParityTest): its unit of work and its
 * repositories, what the services of a flow are made with - Doctrine's as omnibridge/symfony wires them (the
 * container's), or omnibridge/eloquent's on the same database with omnibase's lifecycle attached (OmnibaseHarness);
 * the application's repositories are the same classes on both (App\Repository\*, over each side's Finders).
 * Everything else a service needs (the parameters, the notifier, the validator, the translator) is the container's
 * on both.
 */
final class FlowSide
{
    private function __construct(
        public readonly string $backend,
        public readonly UnitOfWork $unitOfWork,
        public readonly ContainerInterface $container,
        private readonly ?EloquentPersistence $persistence,
    ) {
    }

    public static function doctrine(ContainerInterface $container): self
    {
        return new self('doctrine', UnitOfWorks::of(self::entityManager($container)), $container, null);
    }

    public static function eloquent(ContainerInterface $container, EloquentPersistence $persistence): self
    {
        return new self('eloquent', $persistence->unitOfWork(), $container, $persistence);
    }

    /**
     * The repository answering a core or an application interface, on this backend.
     *
     * @template T of object
     *
     * @param class-string<T>   $interface
     * @param class-string|null $class     the entity it reads, when the interface's own is not the application's
     *
     * @return T&Entities
     */
    public function repository(string $interface, ?string $class = null): object
    {
        if (null === $this->persistence) {
            return null !== $class && !$this->container->has($interface) ? UnitOfWorks::repositories($this->unitOfWork)->of($class) : $this->container->get($interface);
        }

        if (isset(Aliases::INTERFACES[$interface])) {
            return $this->persistence->repositories()->implementing($interface, $class);
        }
        // An application's interface: the entity of the repository the container binds to it, the same class here.
        $repository = $this->persistence->repositories()->of($class ?? $this->container->get($interface)->getClassName());
        if (!$repository instanceof $interface) {
            throw new \LogicException(sprintf('The repository of %s on Eloquent (%s) is no %s.', $repository->getClassName(), $repository::class, $interface));
        }

        return $repository;
    }

    /** @template T of object @param class-string<T> $class @return T|null */
    public function find(string $class, mixed $id): ?object
    {
        return null === $this->persistence ? self::entityManager($this->container)->find($class, $id) : $this->persistence->store()->find($class, $id);
    }

    /**
     * The identity map emptied: the next reads make new objects, from the database - Doctrine's second-level cache
     * emptied too (Eloquent has none, step F7c-2), so that a read after a flow says what the flow wrote.
     */
    public function clear(): void
    {
        if (null !== $this->persistence) {
            $this->persistence->store()->clear();

            return;
        }
        $entityManager = self::entityManager($this->container);
        $entityManager->clear();
        if (null !== $cache = $entityManager->getCache()) {
            $cache->evictEntityRegions();
            $cache->evictCollectionRegions();
            $cache->evictQueryRegions();
        }
    }

    public function persistence(): ?EloquentPersistence
    {
        return $this->persistence;
    }

    private static function entityManager(ContainerInterface $container): EntityManagerInterface
    {
        return $container->get('doctrine')->getManager();
    }
}
