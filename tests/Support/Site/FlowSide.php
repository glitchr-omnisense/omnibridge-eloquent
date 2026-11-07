<?php

namespace Omnibridge\Eloquent\Tests\Support\Site;

use Base\Database\Repository\Entities;
use Base\Database\UnitOfWorks;
use Doctrine\ORM\EntityManagerInterface;
use Omnibridge\Eloquent\Omnibase\EloquentPersistence;
use Omnibridge\Persistence\UnitOfWork;
use Psr\Container\ContainerInterface;

/**
 * One backend of the flows compared at step F7c (third part, tests/Site/FlowParityTest): its unit of work and its
 * repositories, what the services of a flow are made with - Doctrine's as omnibridge/symfony wires them (the
 * container's), or omnibridge/eloquent's on the same database with omnibase's lifecycle attached (OmnibaseHarness) and
 * lesringostars' two App repositories the flows reach (EloquentSubscribers, EloquentDestinations). Everything else a
 * service needs (the parameters, the notifier, the validator, the translator) is the container's on both.
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
        $persistence->repositories()->register('App\\Entity\\Newsletter\\Subscriber', EloquentSubscribers::class);
        $persistence->repositories()->register('App\\Entity\\Destination\\Destination', EloquentDestinations::class);

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

        return $this->persistence->repositories()->implementing($interface, $class);
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
