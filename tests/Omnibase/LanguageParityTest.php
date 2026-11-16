<?php

namespace Omnibridge\Eloquent\Tests\Omnibase;

use Base\Database\Repository\Finder\Aggregate;
use Base\Database\Repository\Finder\Change;
use Base\Database\Repository\Finder\Criterion;
use Base\Database\Repository\Finder\Field;
use Base\Database\Repository\Finder\Group;
use Base\Database\Repository\Finder\Parser;
use Base\Database\Repository\Finders;
use Base\Database\Repository\ServiceEntityRepository;
use Doctrine\DBAL\DriverManager;
use Omnibridge\Doctrine\EntityManagers;
use Omnibridge\Doctrine\Omnibase\DoctrineFinders;
use Omnibridge\Eloquent\Casts;
use Omnibridge\Eloquent\Connections;
use Omnibridge\Eloquent\EloquentUnitOfWork;
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
 * THE language of the ensemble, one feature per case, the same calls and answers on Doctrine and on Eloquent over
 * the same SQLite rows (fresh rows per case): findValuesBy() and Aggregate, updateBy() and Change, deleteBy() on any
 * criteria, Criterion::exists()/notExists()/greatestPer()/hasAll(), Field::of(), lockOneBy(), sql(), and paths through
 * two associations in an order and in a group. Each case is modelled on a statement of the inventory
 * (_tools/foundation/statements-inventory.tsv) it replaces.
 */
final class LanguageParityTest extends TestCase
{
    private ?\Doctrine\ORM\EntityManagerInterface $entityManager = null;
    private ?EntityStore $store = null;

    /** @var array<string, int> */
    private array $ids = [];

    /** @return array<string, array{0: string}> */
    public static function backends(): array
    {
        return ['doctrine' => ['doctrine'], 'eloquent' => ['eloquent']];
    }

    private function seed(): void
    {
        $connection = self::connection();
        $description = (new Builder())->build([Thread::class, Article::class, Page::class, Tag::class, Translation::class, User::class, Profile::class]);
        $pdo = $connection->getNativeConnection();
        \assert($pdo instanceof \PDO);
        (new SchemaCreator($pdo))->create($description);
        $this->entityManager = EntityManagers::create($connection, [__DIR__.'/../Fixtures/Entity']);
        $alice = (new User('alice@example.org'))->setScore(10.0)->setVisits(5);
        $bob = (new User('bob@example.org'))->setScore(3.0)->setVisits(1);
        $carol = (new User('carol@example.org'))->setVisits(0);
        $red = new Tag('red');
        $blue = new Tag('blue');
        $one = (new Article('One'))->setAuthor($alice)->setPublishedAt(new \DateTimeImmutable('2026-01-01 10:00:00'))->setMeta(['score' => 7])->addTag($red)->addTag($blue);
        $two = (new Article('Two'))->setAuthor($bob)->setPublishedAt(new \DateTimeImmutable('2026-01-01 14:00:00'))->setMeta(['score' => 9])->addTag($red);
        $three = (new Thread('Three'))->setAuthor($alice)->setParent($one);
        $four = (new Page('Four'))->setAuthor($alice)->setParent($one)->setPosition(4);
        $five = (new Thread('Five'))->setAuthor($bob)->setParent($two);
        foreach ([$alice, $bob, $carol, $red, $blue, $one, $two, $three, $four, $five] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        $this->entityManager->clear();
        $this->ids = array_map(static fn (?int $id): int => (int) $id, ['alice' => $alice->getId(), 'bob' => $bob->getId(), 'carol' => $carol->getId(), 'one' => $one->getId(), 'two' => $two->getId(), 'three' => $three->getId(), 'four' => $four->getId(), 'five' => $five->getId()]);
        $this->store = new EntityStore(Connections::pdo($pdo), new Mapping($description), Casts::utc());
    }

    /**
     * SQLite in memory; or MySQL when LANGUAGE_MYSQL_HOST is set (a database `language_parity` made afresh per case):
     * `_tools/foundation/run-mysql.sh` runs this test there.
     */
    private static function connection(): \Doctrine\DBAL\Connection
    {
        $host = getenv('LANGUAGE_MYSQL_HOST');
        if (false === $host || '' === $host) {
            return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        }
        $server = ['driver' => 'pdo_mysql', 'host' => $host, 'user' => 'root', 'password' => (string) getenv('LANGUAGE_MYSQL_PASSWORD'), 'charset' => 'utf8mb4'];
        $admin = DriverManager::getConnection($server);
        $admin->executeStatement('DROP DATABASE IF EXISTS language_parity');
        $admin->executeStatement('CREATE DATABASE language_parity');
        $admin->close();

        return DriverManager::getConnection($server + ['dbname' => 'language_parity']);
    }

    private function finders(string $backend): Finders
    {
        $this->seed();
        \assert(null !== $this->entityManager && null !== $this->store);

        return 'doctrine' === $backend ? DoctrineFinders::over($this->entityManager) : new EloquentFinders($this->store, new EloquentUnitOfWork($this->store));
    }

    /**
     * @param iterable<mixed> $threads
     *
     * @return list<string>
     */
    private static function titles(iterable $threads): array
    {
        return array_values(array_map(static fn (mixed $thread): string => $thread instanceof Thread ? $thread->getTitle() : get_debug_type($thread), [...$threads]));
    }

    /** forum countPerCategory, forge lowBalances, marketplace currencies, chapaland findRecordOf (JSON), analytics daily. */
    #[DataProvider('backends')]
    public function testFindValuesBy(string $backend): void
    {
        $finders = $this->finders($backend);
        $threads = new ThreadRepository($finders);
        $articles = new ThreadRepository($finders, Article::class);

        self::assertEquals([['author' => $this->ids['alice'], 'threads' => 3], ['author' => $this->ids['bob'], 'threads' => 2]], $threads->findValuesBy(['author', 'threads' => Aggregate::count()], [], ['threads' => 'DESC']), 'grouped by an association');
        self::assertEquals([['author.email' => 'bob@example.org', 'threads' => 2]], $threads->findValuesBy(['author.email', 'threads' => Aggregate::count()], having: [Group::of(Parser::SEPARATOR_AND, Criterion::of('threads', 3, Parser::OPTION_LOWER))]), 'a path, and having');
        self::assertCount(2, $threads->findValuesBy(['author'], distinct: true), 'distinct');
        self::assertEquals([['day' => '2026-01-01', 'published' => 2]], $articles->findValuesBy(['day' => Aggregate::day('publishedAt'), 'published' => Aggregate::count()], [Group::of(Parser::SEPARATOR_AND, Criterion::of('publishedAt', null, Parser::OPTION_NOT_NULL))]), 'a day bucket');
        self::assertEquals([['title' => 'Two', 'score' => 9], ['title' => 'One', 'score' => 7]], $articles->findValuesBy(['title', 'score' => 'meta->score'], [], ['score' => 'DESC']), 'a key of a JSON field');
        self::assertEquals([['visits' => 6, 'best' => 10.0]], (new ServiceEntityRepository($finders, User::class))->findValuesBy(['visits' => Aggregate::sum('visits'), 'best' => Aggregate::max('score')]), 'aggregates of the whole table');
    }

    /** chapaland debitPepettes (a guarded change), forum incrementViews, drive moveUnder. */
    #[DataProvider('backends')]
    public function testUpdateBy(string $backend): void
    {
        $finders = $this->finders($backend);
        $users = new ServiceEntityRepository($finders, User::class);
        $debit = fn (string $email, int $amount): int => $users->updateBy(['email' => $email, Group::of(Parser::SEPARATOR_AND, Criterion::of('visits', $amount, Parser::OPTION_GREATER_EQUAL))], ['visits' => Change::add(-$amount)]);

        self::assertSame(0, $debit('bob@example.org', 5), 'not enough: no row changed');
        self::assertSame(1, $debit('alice@example.org', 5));
        self::assertEquals([['visits' => 0]], $users->findValuesBy(['visits'], ['email' => 'alice@example.org']));
        $threads = new ThreadRepository($finders);
        self::assertSame(2, $threads->updateBy([Group::of(Parser::SEPARATOR_AND, Criterion::of('title', 'T', Parser::OPTION_STARTING_WITH))], ['title' => Change::replacePrefix('T', 't')]));
        self::assertSame(['Five', 'Four', 'One', 'three', 'two'], array_column($threads->findValuesBy(['title'], [], ['title' => 'ASC']), 'title'));
        self::assertSame(1, $threads->updateBy(['title' => 'Five'], ['author' => $this->ids['alice']]), 'an association by its key');
        self::assertSame(4, $threads->count(['author' => $this->ids['alice']]));
    }

    /** office purgeBefore, chapaland deleteUnpaired, drive deleteUnder: deleteBy() on any criteria. */
    #[DataProvider('backends')]
    public function testDeleteByAnyCriteria(string $backend): void
    {
        $threads = new ThreadRepository($this->finders($backend));
        self::assertSame(2, $threads->deleteBy([Group::of(Parser::SEPARATOR_AND, Criterion::of('title', 'F', Parser::OPTION_STARTING_WITH))]), 'a page (two tables) and a thread');
        self::assertSame(['One', 'Three', 'Two'], array_column($threads->findValuesBy(['title'], [], ['title' => 'ASC']), 'title'));
        self::assertSame(0, $threads->deleteBy(['parent.author.email' => 'nobody@example.org']));
    }

    /** forge invoiceable (NOT EXISTS), booking/mailbox "has a related row". */
    #[DataProvider('backends')]
    public function testExistsAndNotExists(string $backend): void
    {
        $users = new ServiceEntityRepository($this->finders($backend), User::class);
        $emails = static fn (array $users): array => array_map(static fn (mixed $user): string => $user instanceof User ? $user->getEmail() : get_debug_type($user), $users);

        self::assertSame(['alice@example.org', 'bob@example.org'], $emails($users->findBy([Criterion::exists(Thread::class, 'author')], ['email' => 'ASC'])));
        self::assertSame(['carol@example.org'], $emails($users->findBy([Criterion::notExists(Thread::class, 'author')])));
        self::assertSame(['bob@example.org'], $emails($users->findBy([Criterion::exists(Thread::class, 'author', ['title' => 'Two'])])));
        self::assertSame(3, $users->count([Criterion::notExists(Thread::class, 'author', ['title' => 'None'])]));
    }

    /** forum findLastPerCategory: the greatest row per group. */
    #[DataProvider('backends')]
    public function testGreatestPer(string $backend): void
    {
        $threads = new ThreadRepository($this->finders($backend));
        self::assertSame(['Three', 'Two'], self::titles($threads->findBy([Criterion::greatestPer('title', 'author')], ['title' => 'ASC'])));
        self::assertSame(['Four', 'Five'], self::titles($threads->findBy([Criterion::greatestPer('id', 'author')], ['id' => 'ASC'])));
        self::assertSame(['Four'], self::titles($threads->findBy(['author' => $this->ids['alice'], Criterion::greatestPer('id', 'author')])), 'among the rows of the other criteria');
    }

    /** classroom findPublishedOfTaxa: related to ALL these values. */
    #[DataProvider('backends')]
    public function testHasAll(string $backend): void
    {
        $threads = new ThreadRepository($this->finders($backend));
        self::assertSame(['One'], self::titles($threads->findBy([Criterion::hasAll('tags.name', ['red', 'blue'])])));
        self::assertSame(['One', 'Two'], self::titles($threads->findBy([Criterion::hasAll('tags.name', ['red'])], ['id' => 'ASC'])));
        self::assertSame([], $threads->findBy([Criterion::hasAll('tags.name', ['red', 'green'])]));
    }

    /** mailbox countWaiting (c.lastSender = d.author), countUnread (me.lastReadAt < c.lastMessageAt). */
    #[DataProvider('backends')]
    public function testFieldOf(string $backend): void
    {
        $threads = new ThreadRepository($this->finders($backend));
        self::assertSame(['Three', 'Four', 'Five'], self::titles($threads->findBy(['author' => Field::of('parent.author')], ['id' => 'ASC'])), 'the same author as the parent');
        self::assertSame(3, $threads->count([Group::of(Parser::SEPARATOR_AND, Criterion::of('id', Field::of('parent.id'), Parser::OPTION_GREATER))]));
        self::assertSame([], $threads->findBy([Group::of(Parser::SEPARATOR_AND, Criterion::of('id', Field::of('parent.id'), Parser::OPTION_LOWER))]));
    }

    /** gitbud nextNumber, marketplace saveNumbered: the row locked in a transaction. */
    #[DataProvider('backends')]
    public function testLockOneBy(string $backend): void
    {
        $finders = $this->finders($backend);
        $users = new ServiceEntityRepository($finders, User::class);
        $locked = $finders->unitOfWork()->transactional(static fn (): ?object => $users->lockOneBy([], ['visits' => 'DESC']));
        self::assertInstanceOf(User::class, $locked);
        self::assertSame('alice@example.org', $locked->getEmail());
        self::assertNull($users->lockOneBy(['email' => 'nobody@example.org'], skipLocked: true));
    }

    /** video findPopular, ledger lastLetter: ONE SQL body, an order on an expression. */
    #[DataProvider('backends')]
    public function testSql(string $backend): void
    {
        $threads = new ThreadRepository($this->finders($backend));
        self::assertSame(['Three', 'Four'], $threads->longestTitles(2));
        self::assertSame(['Four'], $threads->pagesByPosition(), 'a joined hierarchy: {Page.position} in the page\'s own table');
        $alice = (new ServiceEntityRepository($this->finders($backend), User::class))->findOneBy(['email' => 'alice@example.org']);
        self::assertInstanceOf(User::class, $alice);
        self::assertSame(['One', 'Three', 'Four'], (new ThreadRepository($this->finders($backend)))->titlesOf($alice), 'an entity bound by its identifier, not its __toString()');
    }

    /** marketplace withUnsentDeclarations: Partial on a JSON field is a LIKE on its text (MySQL writes it normalized: `{"score": 9}`). */
    #[DataProvider('backends')]
    public function testPartialOnAJsonField(string $backend): void
    {
        $articles = new ThreadRepository($this->finders($backend), Article::class);
        self::assertSame(['Two'], self::titles($articles->findBy([Group::of(Parser::SEPARATOR_AND, Criterion::of('meta', '%9}', Parser::OPTION_PARTIAL))])));
        self::assertSame(['One', 'Two'], self::titles($articles->findBy([Group::of(Parser::SEPARATOR_AND, Criterion::of('meta', ['%7}', '%9}'], Parser::OPTION_PARTIAL))], ['id' => 'ASC'])), 'any of the patterns');
        self::assertSame(['One'], self::titles($articles->findBy([Group::of(Parser::SEPARATOR_AND, Criterion::of('meta', '%9}', Parser::OPTION_PARTIAL, Parser::OPTION_NOT_EQUAL))])), 'NotEqualTo: NOT LIKE');
        self::assertSame(2, $articles->count([Group::of(Parser::SEPARATOR_AND, Criterion::of('meta', '%SCORE%', Parser::OPTION_INSENSITIVE, Parser::OPTION_PARTIAL))]), 'Insensitive');
    }

    /** estate findOverdueFirst (an order through two associations), a path through two associations in a group. */
    #[DataProvider('backends')]
    public function testPathsThroughTwoAssociations(string $backend): void
    {
        $threads = new ThreadRepository($this->finders($backend));
        self::assertSame(['Five', 'Three', 'Four'], self::titles($threads->findBy([Group::of(Parser::SEPARATOR_AND, Criterion::of('parent', null, Parser::OPTION_NOT_NULL))], ['parent.author.email' => 'DESC', 'id' => 'ASC'])), 'an order');
        self::assertSame(['Five'], self::titles($threads->findBy([], ['parent.author.email' => 'DESC', 'id' => 'ASC'], 1)), 'with a limit');
        self::assertSame(['One', 'Five'], self::titles($threads->findBy([Group::of(Parser::SEPARATOR_OR, Criterion::of('parent.author.email', 'bob%', Parser::OPTION_PARTIAL), Criterion::of('title', 'One'))], ['id' => 'ASC'])), 'in a group');
        // A path that resolves to no row is FALSE where it stands, never "no condition" (eb-3).
        self::assertSame(1, $threads->count([Group::of(Parser::SEPARATOR_OR, Criterion::of('title', 'One'), Criterion::of('parent.author.email', 'nobody@example.org'))]), 'an Or: the other member alone');
        self::assertSame(0, $threads->count([Group::of(Parser::SEPARATOR_AND, Criterion::of('title', 'One'), Criterion::of('parent.author.email', 'nobody@example.org'))]), 'an And: nothing');
        self::assertSame(0, $threads->count([Group::of(Parser::SEPARATOR_OR, Criterion::of('parent.author.email', 'nobody@example.org'), Criterion::of('id', Field::of('parent.id'), Parser::OPTION_LOWER))]), 'two empty members');
    }
}
