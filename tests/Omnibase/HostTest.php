<?php

namespace Omnibridge\Eloquent\Tests\Omnibase;

use Base\Contract\SecurityUserLoader;
use Base\Contract\SecurityUserProvider;
use Base\Database\Repository\Entities;
use Base\Database\Repository\Repositories;
use Base\Repository\Thread\Tags;
use Base\Repository\Users;
use Doctrine\DBAL\DriverManager;
use Omnibridge\Container;
use Omnibridge\Doctrine\EntityManagers;
use Omnibridge\Eloquent\Connections;
use Omnibridge\Eloquent\Container as EloquentContainer;
use Omnibridge\Eloquent\EloquentUnitOfWork;
use Omnibridge\Eloquent\Omnibase\EloquentPersistence;
use Omnibridge\Eloquent\Omnibase\EloquentRepositories;
use Omnibridge\Eloquent\Omnibase\Lifecycle\EntityDispatchersListener;
use Omnibridge\Eloquent\Omnibase\Repository\Thread\TagRepository;
use Omnibridge\Eloquent\Omnibase\Repository\UserRepository;
use Omnibridge\Eloquent\Tests\Fixtures\Entity\Article;
use Omnibridge\Eloquent\Tests\Fixtures\Entity\Page;
use Omnibridge\Eloquent\Tests\Fixtures\Entity\Profile;
use Omnibridge\Eloquent\Tests\Fixtures\Entity\Tag;
use Omnibridge\Eloquent\Tests\Fixtures\Entity\Thread;
use Omnibridge\Eloquent\Tests\Fixtures\Entity\Translation;
use Omnibridge\Eloquent\Tests\Fixtures\Entity\User;
use Omnibridge\Eloquent\UnsupportedPersistence;
use Omnibridge\Exception\PersistenceException;
use Omnibridge\Persistence\Backend;
use Omnibridge\Persistence\Persistences;
use Omnibridge\Persistence\Snapshots;
use Omnibridge\Persistence\UnitOfWork;
use Omnibridge\Schema\Builder;
use Omnibridge\Schema\Database\SchemaCreator;
use Omnibridge\Schema\Description;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * omnibridge/eloquent as a host wires it (plan "omnibase agnostic", step F7d): found by Omnibridge\Container where it is
 * installed - refused aloud beside omnibridge/doctrine unless the parameter `persistence` names one (R8) -, its services
 * declared as data (the unit of work, backend, repositories, the core's repository interfaces), omnibase's lifecycle
 * attached from the host's services when first needed (the entity dispatchers too: the container's own), reads without
 * an AttributeReader and writes refused, reset() between two requests, the users' repository as Security's loader.
 */
final class HostTest extends TestCase
{
    private \PDO $pdo;
    private Description $description;

    protected function setUp(): void
    {
        if (!interface_exists(Entities::class)) {
            self::markTestSkipped('omnisense/omnibase is not installed.');
        }
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->description = (new Builder())->build([Thread::class, Article::class, Page::class, Tag::class, Translation::class, User::class, Profile::class]);
        (new SchemaCreator($connection->getNativeConnection()))->create($this->description);
        $this->pdo = $connection->getNativeConnection();
        $this->entityManager = EntityManagers::create($connection, [__DIR__.'/../Fixtures/Entity']);
        $this->entityManager->persist(new User('alice@example.org'));
        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    private \Doctrine\ORM\EntityManagerInterface $entityManager;

    private static function none(): ContainerInterface
    {
        return new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \LogicException($id);
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
    }

    public function testBesideDoctrineTheContainerAsksWhichPersistence(): void
    {
        self::assertSame(['doctrine', 'eloquent'], Persistences::installed());

        $this->expectException(PersistenceException::class);
        $this->expectExceptionMessage('omnibridge/doctrine and omnibridge/eloquent are installed: the container\'s parameter "persistence" (or the environment variable OMNIBRIDGE_PERSISTENCE) names the one to run on (doctrine|eloquent)');
        Container::fromDefinitions([]);
    }

    public function testThePlainContainerRunsOnEloquentWhenItSaysSo(): void
    {
        $container = Container::fromDefinitions([], ['persistence' => 'eloquent', 'kernel.project_dir' => sys_get_temp_dir()], [
            \Illuminate\Database\Connection::class => Connections::pdo($this->pdo),
            Description::class => $this->description,
        ]);

        $unitOfWork = $container->get(UnitOfWork::class);
        self::assertInstanceOf(EloquentUnitOfWork::class, $unitOfWork);
        self::assertSame($unitOfWork, $container->get(Snapshots::class));
        self::assertSame($unitOfWork, $container->get(EloquentPersistence::class)->unitOfWork());
        self::assertSame('eloquent', $container->get(Backend::class)->name());
        self::assertInstanceOf(EloquentRepositories::class, $container->get(Repositories::class));
        self::assertInstanceOf(UserRepository::class, $container->get(Users::class));
        self::assertInstanceOf(TagRepository::class, $container->get(Tags::class));
        self::assertArrayNotHasKey(Repositories::class, EloquentContainer::interfaces());
        self::assertArrayHasKey(Users::class, EloquentContainer::interfaces());
        self::assertNull(EloquentContainer::interfaces()[Tags::class]);

        // Read through the container's persistence; no AttributeReader in plain PHP: reads only, a write refused aloud.
        $alice = $container->get(EloquentPersistence::class)->store()->find(User::class, 1);
        self::assertSame('alice@example.org', $alice->getEmail());
        if (\Base\Attributes\AttributeReader::hasInstance()) {
            self::markTestIncomplete('An AttributeReader of another test is there: the refusal of a write without one is not observable here.');
        }
        $unitOfWork->persist(new User('bob@example.org'));
        try {
            $unitOfWork->flush();
            self::fail('A write without omnibase\'s behaviours is refused.');
        } catch (UnsupportedPersistence $refusal) {
            self::assertStringContainsString('write without omnibase\'s behaviours', $refusal->getMessage());
        }
        self::assertSame(1, $this->entityManager->getRepository(User::class)->count([]));
    }

    public function testTheHostsServicesAreAskedWhenFirstNeeded(): void
    {
        $asked = [];
        $services = new class($asked) implements ContainerInterface {
            /** @param list<string> $asked */
            public function __construct(public array &$asked)
            {
            }

            public function get(string $id): mixed
            {
                $this->asked[] = $id;

                return null;
            }

            public function has(string $id): bool
            {
                return 'Base\Service\SettingBagInterface' === $id;
            }
        };
        $dispatchersRead = 0;
        $persistence = EloquentPersistence::host(Connections::pdo($this->pdo), $services, ['project_dir' => sys_get_temp_dir(), 'lifecycle' => [
            'entity_dispatchers' => static function () use (&$dispatchersRead): array {
                ++$dispatchersRead;

                return [];
            },
        ]], $this->description);

        self::assertSame([], $asked, 'nothing made with the persistence');
        self::assertSame(0, $dispatchersRead);
        $listeners = $persistence->lifecycle();
        self::assertNotNull($listeners->get(EntityDispatchersListener::class));
        self::assertNotNull($listeners->get(\Omnibridge\Eloquent\Omnibase\Lifecycle\SettingListener::class));
        self::assertNull($listeners->get(\Omnibridge\Eloquent\Omnibase\Lifecycle\ThreadListener::class), 'what the host has not is not attached');

        $persistence->store()->find(User::class, 1);
        self::assertSame(1, $dispatchersRead, 'the dispatchers read at the first entity event');
        $persistence->store()->clear();
        $persistence->store()->find(User::class, 1);
        self::assertSame(1, $dispatchersRead, 'and once');
    }

    public function testResetForgetsWhatTheUnitOfWorkHeld(): void
    {
        $persistence = EloquentPersistence::host(Connections::pdo($this->pdo), self::none(), ['project_dir' => sys_get_temp_dir()], $this->description);
        $alice = $persistence->store()->find(User::class, 1);
        $bob = new User('bob@example.org');
        $persistence->unitOfWork()->persist($bob);
        self::assertTrue($persistence->unitOfWork()->contains($alice));
        self::assertTrue($persistence->unitOfWork()->isScheduledForInsert($bob));

        $persistence->reset();

        self::assertFalse($persistence->unitOfWork()->contains($alice));
        self::assertFalse($persistence->unitOfWork()->isScheduledForInsert($bob));
        self::assertNotSame($alice, $persistence->store()->find(User::class, 1));
    }

    public function testTheUsersRepositoryIsSecuritysLoaderAndProvider(): void
    {
        self::assertTrue(is_a(UserRepository::class, SecurityUserLoader::class, true));
        self::assertTrue(is_a(UserRepository::class, SecurityUserProvider::class, true));
        self::assertTrue(is_a(UserRepository::class, Users::class, true));
        // The same contract as omnibridge/doctrine's: what a site's security.yaml and omnibridge/symfony's UserProvider ask.
        foreach (['supportsClass', 'loadUserByIdentifier', 'refreshUser', 'upgradePassword'] as $method) {
            self::assertTrue(method_exists(UserRepository::class, $method), $method);
            self::assertSame(
                (string) (new \ReflectionMethod(\Omnibridge\Doctrine\Omnibase\Repository\UserRepository::class, $method))->getReturnType(),
                (string) (new \ReflectionMethod(UserRepository::class, $method))->getReturnType(),
                $method,
            );
        }
    }
}
