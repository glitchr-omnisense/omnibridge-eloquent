<?php

namespace Omnibridge\Eloquent\Tests\Omnibase;

use Base\Database\Repository\Entities;
use Doctrine\DBAL\DriverManager;
use Omnibridge\Doctrine\EntityManagers;
use Omnibridge\Eloquent\Casts;
use Omnibridge\Eloquent\Connections;
use Omnibridge\Eloquent\EloquentUnitOfWork;
use Omnibridge\Eloquent\EntityStore;
use Omnibridge\Eloquent\Mapping;
use Omnibridge\Eloquent\Omnibase\EloquentBackend;
use Omnibridge\Eloquent\Omnibase\EloquentRepositories;
use Omnibridge\Eloquent\Omnibase\OmnibaseCasts;
use Omnibridge\Eloquent\Tests\Fixtures\Entity\Article;
use Omnibridge\Eloquent\Tests\Fixtures\Entity\Thread;
use Omnibridge\Eloquent\Tests\Fixtures\Entity\User;
use Omnibridge\Eloquent\UnsupportedPersistence;
use Omnibridge\Persistence\Backend;
use Omnibridge\Persistence\Scope;
use Omnibridge\Schema\Builder;
use Omnibridge\Schema\Database\SchemaCreator;
use PHPUnit\Framework\TestCase;

/**
 * omnibase's part, with the core installed: its column types read as omnibridge/doctrine's DBAL types read them,
 * the core's Repositories and Entities on the store (a criteria array as Doctrine's findBy() reads it, writes
 * refused), the backend's scopes on every query.
 */
final class OmnibasePartTest extends TestCase
{
    private EntityStore $store;

    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        if (!interface_exists(Entities::class)) {
            self::markTestSkipped('omnisense/omnibase is not installed.');
        }
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $description = (new Builder())->build([Thread::class, Article::class, \Omnibridge\Eloquent\Tests\Fixtures\Entity\Page::class, \Omnibridge\Eloquent\Tests\Fixtures\Entity\Tag::class, \Omnibridge\Eloquent\Tests\Fixtures\Entity\Translation::class, User::class, \Omnibridge\Eloquent\Tests\Fixtures\Entity\Profile::class]);
        (new SchemaCreator($connection->getNativeConnection()))->create($description);
        $entityManager = EntityManagers::create($connection, [__DIR__.'/../Fixtures/Entity']);
        $alice = new User('alice@example.org');
        $bob = new User('bob@example.org');
        $one = (new Article('One'))->setAuthor($alice);
        $two = (new Article('Two'))->setAuthor($bob);
        $three = (new Thread('Three'))->setAuthor($alice);
        foreach ([$alice, $bob, $one, $two, $three] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();
        $this->ids = ['alice' => $alice->getId(), 'bob' => $bob->getId(), 'one' => $one->getId(), 'two' => $two->getId(), 'three' => $three->getId()];
        $this->store = new EntityStore(Connections::pdo($connection->getNativeConnection()), new Mapping($description), Casts::utc());
    }

    public function testOmnibasesColumnTypesReadAsDoctrineReadsThem(): void
    {
        $casts = OmnibaseCasts::create(['project_dir' => sys_get_temp_dir()]);
        self::assertSame(['a' => 1], $casts->toPhp('array', serialize(['a' => 1])));
        self::assertSame(serialize(['a' => 1]), $casts->toDatabase('array', ['a' => 1]));
        self::assertSame("\x01\x02", $casts->toPhp('passkey_base64', base64_encode("\x01\x02")));
        self::assertSame(base64_encode("\x01\x02"), $casts->toDatabase('passkey_base64', "\x01\x02"));
        self::assertSame(['type' => 'none'], $casts->toPhp('passkey_trust_path', '{"type":"none"}'));
        $date = $casts->toPhp('datetime', '2026-11-07 10:20:30');
        self::assertSame(['2026-11-07 10:20:30', 'UTC'], [$date->format('Y-m-d H:i:s'), $date->getTimezone()->getName()]);
        self::assertSame('publish', $casts->toPhp('thread_state', 'publish'), 'an enumeration through its toPHPValue()');
        self::assertTrue($casts->knows('workflow_state'));
    }

    public function testTheRepositoriesReadACriteriaArrayAndRefuseToWrite(): void
    {
        $repositories = new EloquentRepositories($this->store);
        $threads = $repositories->of(Thread::class);
        $alice = $repositories->oneById(User::class, $this->ids['alice']);
        self::assertSame(['One', 'Three'], array_map(static fn (Thread $thread): string => $thread->getTitle(), $threads->findBy(['author' => $alice], ['title' => 'ASC'])));
        self::assertSame(['Two'], array_map(static fn (Thread $thread): string => $thread->getTitle(), $threads->findBy(['author' => $this->ids['bob']])));
        self::assertSame(3, $threads->count());
        self::assertSame(2, $repositories->of(Article::class)->count());
        self::assertSame(1, $threads->count(['title' => ['One', 'Nope']]));
        self::assertSame('Two', $threads->findOneBy(['title' => 'Two'])?->getTitle());
        self::assertNull($threads->findOneBy(['parent' => 1]));
        self::assertSame([], $threads->findBy(['parent' => null]), 'null compares `= NULL`, as omnibase\'s finder (ServiceEntityRepository::findBy()) compares it');
        self::assertCount(3, $threads->findByParentNull()->getResult());
        self::assertSame([$this->ids['two'], $this->ids['one']], array_map(static fn (Thread $thread): ?int => $thread->getId(), $repositories->byIds(Thread::class, [$this->ids['two'], $this->ids['one']])));
        self::assertSame(['Two', 'Three'], array_map(static fn (Thread $thread): string => $thread->getTitle(), $threads->findBy([], ['title' => 'DESC'], 2)));
        $this->expectException(UnsupportedPersistence::class);
        $threads->persist($alice, true);
    }

    public function testTheBackendsScopesApplyToEveryQuery(): void
    {
        $backend = new EloquentBackend(new EloquentUnitOfWork($this->store), [new class implements Scope {
            public function name(): string { return 'not-bob'; }
            public function appliesTo(string $class): bool { return User::class === $class; }
            public function criteria(string $class, array $parameters = []): array { return ['email' => ['alice@example.org', null]]; }
        }]);
        self::assertSame('eloquent', $backend->name());
        self::assertTrue($backend->supports(Backend::LAZY_ASSOCIATIONS));
        self::assertTrue($backend->supports(Backend::TRANSACTIONS));
        self::assertFalse($backend->supports(Backend::SECOND_LEVEL_CACHE));
        $backend->enableScope('not-bob');
        self::assertTrue($backend->isScopeEnabled('not-bob'));
        self::assertNull($this->store->find(User::class, $this->ids['bob']));
        self::assertNotNull($this->store->find(User::class, $this->ids['alice']));
        $backend->disableScope('not-bob');
        self::assertNotNull($this->store->find(User::class, $this->ids['bob']));
        $this->expectException(\InvalidArgumentException::class);
        $backend->enableScope('nothing');
    }
}
