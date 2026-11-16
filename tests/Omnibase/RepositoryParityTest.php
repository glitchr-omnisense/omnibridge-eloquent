<?php

namespace Omnibridge\Eloquent\Tests\Omnibase;

use Base\Database\Repository\EntityRepositories;
use Base\Database\Repository\Finder\Criterion;
use Base\Database\Repository\Finder\Group;
use Base\Database\Repository\Finder\Parser;
use Base\Database\Repository\Finder\Result;
use Base\Database\Repository\Finders;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Repository\UnsupportedQueryException;
use Base\Service\ArrayParameterBag;
use Base\Service\Paginator;
use Doctrine\DBAL\DriverManager;
use Omnibridge\Doctrine\EntityManagers;
use Omnibridge\Doctrine\Omnibase\DoctrineFinders;
use Omnibridge\Eloquent\Casts;
use Omnibridge\Eloquent\Connections;
use Omnibridge\Eloquent\EntityStore;
use Omnibridge\Eloquent\Mapping;
use Omnibridge\Eloquent\Omnibase\EloquentFinders;
use Omnibridge\Eloquent\Tests\Fixtures\Entity\Article;
use Omnibridge\Eloquent\Tests\Fixtures\Entity\Page;
use Omnibridge\Eloquent\Tests\Fixtures\Entity\Profile;
use Omnibridge\Eloquent\Tests\Fixtures\Entity\Tag;
use Omnibridge\Eloquent\Tests\Fixtures\Entity\Thread;
use Omnibridge\Eloquent\Tests\Fixtures\Entity\Translation;
use Omnibridge\Eloquent\Tests\Fixtures\Entity\User;
use Omnibridge\Eloquent\Tests\Fixtures\Repository\ThreadRepository;
use Omnibridge\Schema\Builder;
use Omnibridge\Schema\Database\SchemaCreator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * THE repository test of the ensemble: one agnostic repository class (Fixtures\Repository\ThreadRepository, a
 * Base\Database\Repository\ServiceEntityRepository), the same calls, the same answers on Doctrine (DoctrineFinders
 * over an entity manager) and on Eloquent (EloquentFinders over the store), on the same SQLite rows. What a bundle
 * or a site may rely on: the criteria array (null, lists, groups, paths one association deep, class conditions),
 * the order on several keys and a path, limit and offset, count() in the database, a finder's Result as Pages,
 * EntityRepositories::of() for a class with and without a repository, the statements by convention.
 */
final class RepositoryParityTest extends TestCase
{
    private static ?\Doctrine\ORM\EntityManagerInterface $entityManager = null;
    private static ?EntityStore $store = null;

    /** @var array<string, int> */
    private static array $ids = [];

    public static function setUpBeforeClass(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $description = (new Builder())->build([Thread::class, Article::class, Page::class, Tag::class, Translation::class, User::class, Profile::class]);
        $pdo = $connection->getNativeConnection();
        \assert($pdo instanceof \PDO);
        (new SchemaCreator($pdo))->create($description);
        self::$entityManager = EntityManagers::create($connection, [__DIR__.'/../Fixtures/Entity']);
        $alice = new User('alice@example.org');
        $bob = new User('bob@example.org');
        $one = (new Article('One'))->setAuthor($alice)->setPublishedAt(new \DateTimeImmutable('2026-01-01 10:00:00'));
        $two = (new Article('Two'))->setAuthor($bob)->setPublishedAt(new \DateTimeImmutable('2026-02-01 10:00:00'));
        $three = (new Thread('Three'))->setAuthor($alice)->setParent($one);
        $four = (new Page('Four'))->setAuthor($alice)->setParent($one)->setPosition(4);
        foreach ([$alice, $bob, $one, $two, $three, $four] as $entity) {
            self::$entityManager->persist($entity);
        }
        self::$entityManager->flush();
        self::$entityManager->clear();
        self::$ids = array_map(static fn (?int $id): int => (int) $id, ['alice' => $alice->getId(), 'bob' => $bob->getId(), 'one' => $one->getId(), 'two' => $two->getId(), 'three' => $three->getId(), 'four' => $four->getId()]);
        self::$store = new EntityStore(Connections::pdo($pdo), new Mapping($description), Casts::utc());
    }

    /** @return array<string, array{0: string}> */
    public static function backends(): array
    {
        return ['doctrine' => ['doctrine'], 'eloquent' => ['eloquent']];
    }

    private function finders(string $backend): Finders
    {
        if ('doctrine' === $backend) {
            $entityManager = self::$entityManager ?? throw new \LogicException('No entity manager: setUpBeforeClass() did not run.');
            $entityManager->clear();

            return DoctrineFinders::over($entityManager);
        }
        $store = self::$store ?? throw new \LogicException('No store: setUpBeforeClass() did not run.');
        $store->clear();

        return new EloquentFinders($store);
    }

    /**
     * @param mixed $threads a page's result, iterable
     *
     * @return list<string>
     */
    private static function titles(mixed $threads): array
    {
        self::assertIsIterable($threads);

        return array_values(array_map(static fn (mixed $thread): string => $thread instanceof Thread ? $thread->getTitle() : get_debug_type($thread), [...$threads]));
    }

    #[DataProvider('backends')]
    public function testTheCriteriaArray(string $backend): void
    {
        $finders = $this->finders($backend);
        $threads = new ThreadRepository($finders);
        self::assertSame($backend, $finders->backend());
        self::assertSame(Thread::class, $threads->getClassName());

        self::assertSame(['One', 'Two'], self::titles($threads->findBy(['parent' => null], ['id' => 'ASC'])), 'null is IS NULL');
        self::assertSame(['Three', 'Four'], self::titles($threads->findBy(['parent' => self::$ids['one']], ['id' => 'ASC'])), 'an id for an association');
        self::assertSame([], $threads->findBy(['id' => []]), 'an empty list matches nothing');
        self::assertSame(0, $threads->count(['id' => []]));
        self::assertNull($threads->findOneBy(['title' => []]));
        self::assertSame(['One', 'Three'], self::titles($threads->findBy(['title' => ['One', 'Three', 'None']], ['title' => 'ASC'])), 'a list is IN');
        self::assertSame(3, $threads->count(['author.email' => 'alice@example.org']), 'a path one association deep');
        self::assertSame(['Four', 'One', 'Three'], self::titles($threads->findBy(['author.email' => 'alice@example.org'], ['title' => 'ASC'])));
        self::assertSame(['Two', 'Three', 'One', 'Four'], self::titles($threads->findBy([], ['author.email' => 'DESC', 'title' => 'DESC'])), 'an order on a path, then a key');
        self::assertSame(['Three', 'Four'], self::titles($threads->findBy([], ['author.email' => 'ASC', 'id' => 'ASC'], 2, 1)), 'limit and offset');
    }

    #[DataProvider('backends')]
    public function testAPathThroughTwoAssociations(string $backend): void
    {
        $threads = new ThreadRepository($this->finders($backend));
        self::assertSame(['Three', 'Four'], self::titles($threads->findBy(['parent.author.email' => 'alice@example.org'], ['id' => 'ASC'])), 'a criterion');
        self::assertSame(2, $threads->count(['parent.author.email' => 'alice@example.org']), 'a count');
        self::assertSame([], $threads->findBy(['parent.author.email' => 'nobody@example.org']), 'no related record: no row');
        self::assertSame(['Three'], self::titles($threads->findBy(['parent.author.email' => 'alice@example.org', 'title' => 'Three'])), 'beside another criterion');

        self::assertSame(['Three', 'Four'], self::titles($threads->findBy(['parent.author.email' => 'alice@example.org'], ['parent.author.email' => 'ASC', 'id' => 'ASC'])), 'an order through two associations');
    }

    #[DataProvider('backends')]
    public function testTheGroupsAndTheClassConditions(string $backend): void
    {
        $threads = new ThreadRepository($this->finders($backend));
        $search = Group::of(Parser::SEPARATOR_OR,
            Criterion::of('title', '%o%', Parser::OPTION_INSENSITIVE, Parser::OPTION_PARTIAL),
            Criterion::of('author.email', 'bob%', Parser::OPTION_PARTIAL),
        );
        self::assertSame(['Four', 'One', 'Two'], self::titles($threads->findBy([$search], ['title' => 'ASC'])), 'a search group on a field and a path');
        self::assertSame(3, $threads->count([$search]), 'count() on the same criteria');
        self::assertSame(['Two'], self::titles($threads->findBy([$search, 'author' => self::$ids['bob']])));
        self::assertSame(['Four', 'One'], self::titles($threads->findBy([Group::of(Parser::SEPARATOR_AND, Criterion::of('title', ['F%', 'O%'], Parser::OPTION_PARTIAL))], ['title' => 'ASC'])), 'a list of patterns: any of them');
        self::assertSame(['Four', 'One', 'Three'], self::titles($threads->findBy([Group::of(Parser::SEPARATOR_AND, Criterion::of('author.email', ['alice%', 'nobody%'], Parser::OPTION_PARTIAL))], ['title' => 'ASC'])), 'a list of patterns on a path');

        self::assertSame(['Three'], self::titles($threads->findBy([Group::of(Parser::SEPARATOR_AND, Criterion::notInstanceOf(Article::class, Page::class))])), 'NotInstanceOf from code');
        self::assertSame(['One', 'Two'], self::titles($threads->findBy([Group::of(Parser::SEPARATOR_AND, Criterion::instanceOf(Article::class))], ['id' => 'ASC'])));
        self::assertSame(['Four'], self::titles($threads->findBy([Group::of(Parser::SEPARATOR_AND, Criterion::classOf(Page::class))])));
        self::assertSame(3, $threads->count([Group::of(Parser::SEPARATOR_AND, Criterion::notClassOf(Page::class))]));
        self::assertSame(['Three'], self::titles($threads->findBy([Group::by('NotInstanceOf', [Article::class, Page::class])])), 'the same by a name');

        $neighbour = $threads->findOneBy([Group::of(Parser::SEPARATOR_AND, Criterion::of('id', self::$ids['two'], Parser::OPTION_GREATER))], ['id' => 'ASC']);
        self::assertInstanceOf(Thread::class, $neighbour, 'the next record');
        self::assertSame('Three', $neighbour->getTitle());
    }

    #[DataProvider('backends')]
    public function testAFindersResultIsPages(string $backend): void
    {
        $threads = new ThreadRepository($this->finders($backend));
        $result = $threads->findByAuthor(self::$ids['alice'], [], ['title' => 'ASC']);
        self::assertInstanceOf(Result::class, $result);
        self::assertSame(3, $result->count());
        self::assertSame(['One', 'Three'], self::titles($result->slice(1, 2)));
        self::assertSame(['Four', 'One', 'Three'], self::titles($result));
        $three = $threads->findOneByTitle('Three');
        self::assertInstanceOf(Thread::class, $three);
        self::assertSame('Three', $three->getTitle());
        self::assertSame(['One', 'Two'], $threads->publishedTitles());
        self::assertSame(4, $threads->countBy([]));

    }

    #[DataProvider('backends')]
    public function testThePaginatorPagesARepositoryWithItsCriteria(string $backend): void
    {
        $threads = new ThreadRepository($this->finders($backend));
        $paginator = new Paginator($this->createStub(\Base\Contract\Urls::class), new ArrayParameterBag([
            'base.paginator.page_size' => 10, 'base.paginator.page_range' => 3, 'base.paginator.page_parameter' => 'page',
            'base.paginator.default_template' => '@Base/paginator/sliding.html.twig',
        ]));

        $first = $paginator->paginate($threads, ['author.email' => 'alice@example.org'], ['title' => 'DESC'], pageSize: 2);
        self::assertSame(3, $first->getTotalCount(), 'counted in the database, criteria included');
        self::assertEquals(2, $first->getTotalPages());
        self::assertSame(['Three', 'One'], self::titles($first->getResult()));
        $second = $paginator->paginate($threads, ['author.email' => 'alice@example.org'], ['title' => 'DESC'], page: 2, pageSize: 2);
        self::assertSame(['Four'], self::titles($second->getResult()));
        self::assertSame(['Four', 'One', 'Three', 'Two'], self::titles($paginator->paginate($threads, [], ['title' => 'ASC'])->getResult()), 'no criteria: every row, the default page size');

        $byAuthor = $threads->findByAuthor(self::$ids['alice'], [], ['title' => 'ASC']);
        self::assertInstanceOf(\Base\Service\Model\Pages::class, $byAuthor);
        $result = $paginator->paginate($byAuthor, page: 2, pageSize: 2);
        self::assertSame(3, $result->getTotalCount(), 'a finder\'s Result');
        self::assertSame(['Three'], self::titles($result->getResult()));
        $rows = $paginator->paginate(['a', 'b', 'c'], page: 3, pageSize: 1)->getResult();
        self::assertIsArray($rows);
        self::assertSame(['c'], array_values($rows), 'rows');

        $this->expectException(\InvalidArgumentException::class);
        $paginator->paginate(['a'], ['title' => 'One']);
    }

    #[DataProvider('backends')]
    public function testEveryClassGetsARepositoryWithTheLanguage(string $backend): void
    {
        $repositories = new EntityRepositories($this->finders($backend));
        // A class without a repository: the agnostic parent itself, the whole language.
        $users = $repositories->of(User::class);
        self::assertSame(ServiceEntityRepository::class, $users::class);
        self::assertInstanceOf(ServiceEntityRepository::class, $users);
        self::assertSame(User::class, $users->getClassName());
        self::assertSame(['alice@example.org'], array_map(static fn (object $user): string => $user instanceof User ? $user->getEmail() : get_debug_type($user), $users->findBy([Group::of(Parser::SEPARATOR_AND, Criterion::of('email', 'alice%', Parser::OPTION_PARTIAL))])));
        self::assertSame(2, $users->count());
        self::assertSame(1, $users->countByEmailStartingWith('bob'));
        $bob = $repositories->oneById(User::class, self::$ids['bob']);
        self::assertInstanceOf(User::class, $bob);
        self::assertSame(self::$ids['bob'], $bob->getId());
        self::assertCount(2, $repositories->byIds(User::class, [self::$ids['alice'], self::$ids['bob']]));
        // A repository taking the repositories and the unit of work beside the Finders (omnibase/blog's PostRepository).
        $tags = $repositories->of(Tag::class);
        self::assertInstanceOf(\Omnibridge\Eloquent\Tests\Fixtures\Repository\TagRepository::class, $tags);
        self::assertSame($repositories, $tags->repositories);
        $withUnitOfWork = new EntityRepositories('doctrine' === $backend ? $this->finders($backend) : new EloquentFinders(self::$store ?? throw new \LogicException(), new \Omnibridge\Eloquent\EloquentUnitOfWork(self::$store)));
        $tagsWithUnitOfWork = $withUnitOfWork->of(Tag::class);
        self::assertInstanceOf(\Omnibridge\Eloquent\Tests\Fixtures\Repository\TagRepository::class, $tagsWithUnitOfWork);
        self::assertInstanceOf(\Omnibridge\Persistence\UnitOfWork::class, $tagsWithUnitOfWork->unitOfWork);
        // A subclass of an entity with a registered repository gets it.
        $repositories->register(Thread::class, ThreadRepository::class);
        self::assertInstanceOf(ThreadRepository::class, $repositories->of(Article::class));
        self::assertSame(Article::class, $repositories->of(Article::class)->getClassName());
        self::assertSame(2, $repositories->of(Article::class)->count());
    }


    #[DataProvider('backends')]
    public function testDeleteByDeletesInTheDatabase(string $backend): void
    {
        $entityManager = self::$entityManager ?? throw new \LogicException('No entity manager.');
        $store = self::$store ?? throw new \LogicException('No store.');
        $bob = $entityManager->find(User::class, self::$ids['bob']) ?? throw new \LogicException('No bob.');
        foreach (['Temp', 'Temp2', 'Temp3'] as $title) {
            $entityManager->persist((new Thread($title))->setAuthor($bob));
        }
        $entityManager->flush();
        $entityManager->clear();
        $store->clear();

        $finders = 'doctrine' === $backend ? DoctrineFinders::over($entityManager) : new EloquentFinders($store, new \Omnibridge\Eloquent\EloquentUnitOfWork($store));
        $threads = new ThreadRepository($finders);
        self::assertSame(1, $threads->deleteBy(['title' => 'Temp']), 'a field');
        $author = $finders->of(User::class)->find(self::$ids['bob']);
        self::assertInstanceOf(User::class, $author);
        self::assertSame(1, $threads->deleteBy(['author' => $author, 'title' => 'Temp2']), 'a to-one association by its entity');
        self::assertSame(0, $threads->deleteBy(['author' => self::$ids['alice'], 'title' => 'Temp3']), 'by an id: alice has no Temp3');
        self::assertSame(1, $threads->deleteBy(['parent' => null, 'title' => 'Temp3']), 'null is IS NULL');
        self::assertSame(0, $threads->count(['title' => ['Temp', 'Temp2', 'Temp3']]));
        self::assertSame(4, $threads->count(), 'the other rows kept');
    }

}
