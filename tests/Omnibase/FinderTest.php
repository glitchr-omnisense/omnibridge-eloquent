<?php

namespace Omnibridge\Eloquent\Tests\Omnibase;

use Base\Database\Repository\Entities;
use Base\Database\Repository\Finder\Group;
use Base\Database\Repository\Finder\Key;
use Base\Database\Repository\UnsupportedQueryException;
use Doctrine\DBAL\DriverManager;
use Omnibridge\Doctrine\EntityManagers;
use Omnibridge\Eloquent\Casts;
use Omnibridge\Eloquent\Connections;
use Omnibridge\Eloquent\EntityStore;
use Omnibridge\Eloquent\Mapping;
use Omnibridge\Eloquent\Omnibase\EloquentEntities;
use Omnibridge\Eloquent\Omnibase\Finder\FinderQuery;
use Omnibridge\Eloquent\Tests\Fixtures\Entity\Article;
use Omnibridge\Eloquent\Tests\Fixtures\Entity\Page;
use Omnibridge\Eloquent\Tests\Fixtures\Entity\Tag;
use Omnibridge\Eloquent\Tests\Fixtures\Entity\Thread;
use Omnibridge\Eloquent\Tests\Fixtures\Entity\Translation;
use Omnibridge\Eloquent\Tests\Fixtures\Entity\User;
use Omnibridge\Schema\Builder;
use Omnibridge\Schema\Database\SchemaCreator;
use PHPUnit\Framework\TestCase;

/**
 * omnibase's finder language compiled by EloquentFinder on the fixtures (SQLite): each word, the paths through an
 * association (to-one, many-to-many, one-to-many), the key, the groups, the requests and their shapes, the order,
 * what is refused. The comparison with omnibridge/doctrine's compiler on the same database is FinderParityTest's
 * (tests/Harness on omnisense/omnibase's harness, tests/Site on an application).
 */
final class FinderTest extends TestCase
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
        $description = (new Builder())->build([Thread::class, Article::class, Page::class, Tag::class, Translation::class, User::class, \Omnibridge\Eloquent\Tests\Fixtures\Entity\Profile::class]);
        (new SchemaCreator($connection->getNativeConnection()))->create($description);
        $entityManager = EntityManagers::create($connection, [__DIR__.'/../Fixtures/Entity']);

        $alice = new User('alice@example.org');
        $bob = new User('bob@example.org');
        $red = new Tag('red');
        $blue = new Tag('blue');
        $one = (new Article('One_first'))->setAuthor($alice)->setPublishedAt(new \DateTimeImmutable('-2 days'))->addTag($red)->addTag($blue);
        $two = (new Article('Two'))->setAuthor($bob)->setPublishedAt(new \DateTimeImmutable('+3 days'))->addTag($red);
        $three = (new Thread('three'))->setAuthor($alice)->setParent($one);
        $four = (new Page('Four'))->setParent($one)->setPosition(4);
        foreach ([$alice, $bob, $red, $blue, $one, $two, $three, $four, new Translation($one, 'fr', 'Un'), new Translation($two, 'fr', 'Deux'), new Translation($two, 'en', 'Two')] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();
        $this->ids = ['alice' => $alice->getId(), 'bob' => $bob->getId(), 'red' => $red->getId(), 'blue' => $blue->getId(), 'one' => $one->getId(), 'two' => $two->getId(), 'three' => $three->getId(), 'four' => $four->getId()];
        $this->store = new EntityStore(Connections::pdo($connection->getNativeConnection()), new Mapping($description), Casts::utc());
    }

    public function testTheWordsOfACriterion(): void
    {
        $threads = $this->threads();
        self::assertSame(['One_first'], $this->titles($threads->findByTitle('One_first')));
        self::assertSame('Two', $threads->findOneByTitle('Two')?->getTitle());
        self::assertSame(['One_first', 'Two'], $this->titles($threads->findByTitleOrTitle('Two', 'One_first', [], ['id' => 'ASC'])));
        self::assertSame(['One_first', 'Two', 'three'], $this->titles($threads->findByTitleNotEqualTo('Four', [], ['id' => 'ASC'])));
        self::assertSame(['One_first', 'Four'], $this->titles($threads->findByTitle(['One_first', 'Four', 'None'], [], ['id' => 'ASC'])));
        self::assertCount(4, $threads->findByTitle([])->getResult(), 'an empty list: no condition');
        self::assertSame(['One_first'], $this->titles($threads->findByTitleStartingWith('One')));
        // The underscore escaped with a backslash, as omnibridge/doctrine escapes it: MySQL's LIKE reads the backslash as
        // its escape, SQLite's has none (no ESCAPE clause), so "One_" finds nothing here - on both compilers alike.
        self::assertSame([], $this->titles($threads->findByTitleStartingWith('One_')));
        self::assertSame(['three'], $this->titles($threads->findByTitleEndingWith('ee')));
        self::assertSame(['One_first', 'Two', 'Four'], $this->titles($threads->findByTitleNotStartingWith('th', [], ['id' => 'ASC'])));
        self::assertSame(['three'], $this->titles($threads->findByInsensitiveTitleStartingWith('THR')));
        self::assertSame(['Two'], $this->titles($threads->findByPartialTitle('%w%')));
        self::assertSame(['Two'], $this->titles($threads->findByPublishedAtIsNotOver()));
        self::assertSame(['One_first'], $this->titles($threads->findByPublishedAtIsOver()));
        self::assertSame(['One_first', 'Two'], $this->titles($threads->findByPublishedAtYoungerThan(7 * 86400, [], ['id' => 'ASC'])), 'after a week ago (the future too)');
        self::assertSame(['One_first'], $this->titles($threads->findByPublishedAtOlderThan(86400)));
        self::assertSame(['One_first', 'Two'], $this->titles($threads->findByPublishedAtWithin('+1 week', [], ['id' => 'ASC'])));
        self::assertSame(['three', 'Four'], $this->titles($threads->findByPublishedAtNull([], ['id' => 'ASC'])));
        self::assertSame(['Two', 'three', 'Four'], $this->titles($threads->findByBut($this->ids['one'], [], ['id' => 'ASC'])), 'But: every row but that id');
        self::assertSame([], $this->titles($threads->findBy(['parent' => null])), 'null in a criteria array is `= NULL`, as omnibase\'s finder compares it: no row');
    }

    /** A finder's query is omnibase's Pages (step F7d-2): what Paginator::paginate() pages through, each entity counted once. */
    public function testAFindersQueryIsPagedThroughByOmnibasesPagination(): void
    {
        $threads = $this->threads();
        $all = $threads->findByTitle([], [], ['id' => 'ASC']);
        self::assertInstanceOf(\Base\Service\Model\Pages::class, $all);
        self::assertSame(4, $all->count());
        self::assertSame(['Two', 'three'], $this->titles($all->slice(1, 2)));
        self::assertSame($all, \Base\Service\Model\Pagination::pages($all), 'Pages as they are');
        // The tags' join table gives the first article two rows: one entity, counted once, and paged once.
        $tagged = $threads->findByTags([$this->ids['red'], $this->ids['blue']], [], ['id' => 'ASC']);
        self::assertSame(2, $tagged->count());
        self::assertSame(['One_first'], $this->titles($tagged->slice(0, 1)));
        self::assertSame(['Two'], $this->titles($tagged->slice(1, 5)));
        self::assertSame([], $tagged->slice(2, 5));
    }

    public function testTheAssociationsAreJoinedAndTheKeyIsNot(): void
    {
        $threads = $this->threads();
        self::assertSame(['One_first', 'three'], $this->titles($threads->findByAuthor($this->ids['alice'], [], ['id' => 'ASC'])));
        self::assertSame(['Four'], $this->titles($threads->findByAuthorNull()));
        self::assertSame(['three', 'Four'], $this->titles($threads->findBy(['parent' => Key::of($this->ids['one'])], ['id' => 'ASC'])));
        self::assertSame(['One_first', 'Two'], $this->titles($threads->findBy(['parent' => Key::of(null)], ['id' => 'ASC'])));
        self::assertSame(['three', 'Four'], $this->titles($threads->findByParentNotEqualTo(Key::of(null), [], ['id' => 'ASC'])));
        self::assertSame(['One_first', 'Two'], $this->titles($threads->findBy(['tags' => [$this->ids['red']]], ['id' => 'ASC'])), 'a many-to-many association through its join table');
        self::assertSame(['One_first'], $this->titles($threads->findBy(['tags.name' => 'blue'])));
        self::assertSame(['Two'], $this->titles($threads->findBy(['translations.locale' => 'en'])), 'a one-to-many association on the target\'s key');
        self::assertSame(['One_first', 'Two'], $this->titles($threads->findBy(['author.email' => ['alice@example.org', 'bob@example.org'], 'tags.name' => 'red'], ['id' => 'ASC'])));
        self::assertSame(['Two', 'One_first'], $this->titles($threads->findBy(['parent' => Key::of(null), 'title' => ['One_first', 'Two']], ['author.email' => 'DESC'])));
        self::assertStringNotContainsString('join', strtolower($threads->findByParent(Key::of(1))->getSQL()), 'a key criterion joins nothing');
        self::assertStringContainsString('left join', strtolower($threads->findByParent(1)->getSQL()), 'an association criterion joins it');
    }

    public function testTheDiscriminatorWords(): void
    {
        $threads = $this->threads();
        self::assertSame(['One_first', 'Two'], $this->titles($threads->findByInstanceOf(Article::class, [], ['id' => 'ASC'])));
        self::assertSame(['three'], $this->titles($threads->findByClassOf(Thread::class)));
        self::assertSame(['One_first', 'Two', 'Four'], $this->titles($threads->findByNotClassOf(Thread::class, [], ['id' => 'ASC'])));
        self::assertSame(['three', 'Four'], $this->titles($threads->findByNotInstanceOf(Article::class, [], ['id' => 'ASC'])));
        self::assertSame(['three', 'Four'], $this->titles($threads->findByInstanceOf('^'.Article::class, [], ['id' => 'ASC'])));
        $this->expectException(UnsupportedQueryException::class);
        $threads->findByMemberOf(Article::class);
    }

    public function testTheGroupsAndTheSeparators(): void
    {
        $threads = $this->threads();
        self::assertSame(['One_first', 'three'], $this->titles($threads->findBy(['author' => $this->ids['alice'], Group::by('ParentNullOrTitleEqualTo', 'three')], ['id' => 'ASC'])));
        self::assertSame(['One_first', 'Two', 'three'], $this->titles($threads->findByTitleOrTitle('Two', 'three', [Group::by('ParentNullAndAuthorNotNull')], ['id' => 'ASC'])), 'a group joined by the call\'s separator');
        self::assertSame(3, $threads->count([Group::by('TitleStartingWithOrParentNotNull', 'One')]));
    }

    public function testTheRequestsAndTheirShapes(): void
    {
        $threads = $this->threads();
        $query = $threads->findByAuthorNotNull([], ['id' => 'ASC']);
        self::assertInstanceOf(FinderQuery::class, $query);
        self::assertSame(['One_first', 'Two', 'three'], $this->titles($query));
        self::assertSame(['Four', 'three'], $this->titles($threads->findLast2By([])));
        self::assertSame('Four', $threads->findLastOneBy([])?->getTitle());
        self::assertSame(['One_first'], $this->titles($threads->findAtMost1By([], ['id' => 'ASC'])));
        self::assertSame('Two', $threads->findNextOneBy(['id' => $this->ids['one']])?->getTitle());
        self::assertSame('three', $threads->findPreviousOneBy(['id' => $this->ids['four']])?->getTitle());
        self::assertSame(4, $threads->count());
        self::assertSame(3, $threads->countByAuthorNotNull());
        self::assertSame(3, $threads->countForTagsBy([]), 'a count of the joined tags: three rows of the join');
        self::assertSame(2, $threads->distinctCountForTagsBy([]));
        self::assertSame(2, $threads->distinctCountForAuthorBy([]));
        $grouped = $threads->countByAuthorNotNull([], '', null, ['author']);
        self::assertSame(['entity', 'author', 'count'], array_keys($grouped[0]), 'a grouped count: the entity, the group, the count');
        self::assertSame([$this->ids['one'], $this->ids['two'], $this->ids['three']], array_map(static fn (array $row): int => $row['entity']->getId(), $grouped));
        $lengths = $threads->lengthOfTagsBy([], ['id' => 'ASC']);
        self::assertSame([2, 1, 0, 0], array_map(static fn (array $row): int => (int) $row['length'], $lengths));
        self::assertSame([9, 3, 5, 4], array_map(static fn (array $row): int => (int) $row['length'], $threads->lengthOfTitleBy([], ['id' => 'ASC'])));
        self::assertSame((string) $this->ids['four'], (string) $threads->maxOfIdBy([]));
        self::assertSame((string) array_sum([$this->ids['one'], $this->ids['three']]), (string) $threads->sumOfIdByAuthor($this->ids['alice']));
        self::assertNull($threads->minOfIdByTitle('nothing'));
        $doubled = $threads->findByTitle('Two', [], [], null, null, null, ['twice' => 'e.id * 2'])->getResult();
        self::assertSame([0, 'twice'], array_keys($doubled[0]), 'a selected value beside the entity');
        self::assertSame($this->ids['two'] * 2, (int) $doubled[0]['twice']);
    }

    public function testTheOrders(): void
    {
        $threads = $this->threads();
        self::assertSame(['Two', 'One_first', 'three', 'Four'], $this->titles($threads->findBy([], ['title' => ['Two', 'One_first', 'three', 'Four']])), 'FIELD(): a list of values');
        self::assertSame(['three', 'Four', 'One_first', 'Two'], $this->titles($threads->findBy([], ['parent.title' => 'DESC', 'id' => 'ASC'])), 'a path through an association (NULL first descending on SQLite: none joined)');
        self::assertCount(4, $threads->findRandomlyBy([])->getResult());
        self::assertStringContainsString('RANDOM()', $threads->findRandomlyBy([])->getSQL());
        self::assertSame(['One_first', 'Two'], $this->titles($threads->findByAuthorNotNull([], ['id' => 'ASC'], 2)));
        self::assertSame(['Two', 'three'], $this->titles($threads->findBy([], ['id' => 'ASC'], 2, 1)));
        self::assertSame(['three', 'Four'], $this->titles($threads->findBy([], ['id' => 'ASC'], null, 2)), 'an offset without a limit');
    }

    public function testWhatIsNotCompiledIsRefusedAloud(): void
    {
        $threads = $this->threads();
        foreach ([
            fn () => $threads->findByPartialAuthor('%a%'),
            fn () => $threads->findByPartialTitle(['a', 'b'])->getResult(),
            fn () => $threads->findBy([], ['RANDOM_FUNCTION(e.id)' => 'ASC']),
            fn () => $threads->findBy(['author.profile.bio' => 'x']),
            fn () => $threads->findBy(['nothing' => 1]),
            fn () => $threads->findByTitle('x', [], [], null, null, null, ['a' => 'e.id + :parameter']),
        ] as $index => $call) {
            try {
                $call();
                self::fail('refused: #'.$index);
            } catch (UnsupportedQueryException) {
                self::assertTrue(true);
            }
        }
    }

    private function threads(): EloquentEntities
    {
        return new EloquentEntities($this->store, Thread::class);
    }

    /** @return list<string> */
    private function titles(iterable $threads): array
    {
        $titles = [];
        foreach ($threads as $thread) {
            $titles[] = $thread->getTitle();
        }

        return $titles;
    }
}
