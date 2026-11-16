<?php

namespace Omnibridge\Eloquent\Tests\Omnibase;

use Base\Database\Repository\EntityRepositories;
use Base\Database\UnitOfWorks;
use Omnibridge\Eloquent\EloquentUnitOfWork;
use Omnibridge\Eloquent\Omnibase\EloquentBehaviours;
use Base\Database\Repository\Entities;
use Base\Database\Repository\UnsupportedQueryException;
use Base\Repository\Aliases;
use Omnibridge\Eloquent\Connections;
use Base\Database\Repository\ServiceEntityRepository;
use Omnibridge\Eloquent\Omnibase\EloquentFinders;
use Omnibridge\Eloquent\Omnibase\EloquentPersistence;
use Base\Repository\ThreadRepository;
use Omnibridge\Eloquent\UnsupportedPersistence;
use Omnibridge\Schema\Database\SchemaCreator;
use Omnibridge\Schema\Description;
use PHPUnit\Framework\TestCase;

/**
 * The core's repository interfaces on Eloquent: each implemented (Aliases::INTERFACES, the core's entities described
 * with no Doctrine), a subclass's entity given its parent's repository, the analytics and a redirection's hit (written
 * once in the language) run on Eloquent; the writes through the unit of work.
 */
final class RepositoriesTest extends TestCase
{
    private static ?EloquentPersistence $persistence = null;

    protected function setUp(): void
    {
        if (!interface_exists(Entities::class) || !class_exists(Aliases::class)) {
            self::markTestSkipped('omnisense/omnibase is not installed.');
        }
        self::$persistence ??= EloquentPersistence::create(Connections::pdo(new \PDO('sqlite::memory:')), ['project_dir' => sys_get_temp_dir()]);
    }

    public function testEachInterfaceOfTheCoreIsImplemented(): void
    {
        $repositories = self::$persistence->repositories();
        $interfaces = array_keys(Aliases::INTERFACES);
        self::assertCount(28, $interfaces, 'the 28 repository interfaces of the core');
        foreach ($interfaces as $interface) {
            $repository = $repositories->implementing($interface);
            self::assertInstanceOf($interface, $repository);
            self::assertInstanceOf(ServiceEntityRepository::class, $repository);
        }
        self::assertInstanceOf(\Base\Database\Repository\Repositories::class, $repositories);
    }

    public function testASubclassGetsItsParentsRepositoryAndAnyOtherClassThePlainOne(): void
    {
        $repositories = self::$persistence->repositories();
        self::assertSame(ThreadRepository::class, $repositories->repositoryClass(\Base\Entity\Thread::class));
        // An entity that names no repository gets the agnostic parent itself: the finder language for every class.
        self::assertSame(ServiceEntityRepository::class, $repositories->repositoryClass(\Base\Entity\Layout\ShortLinkIntl::class));
        self::assertSame(ServiceEntityRepository::class, $repositories->of(\Base\Entity\Layout\ShortLinkIntl::class)::class);
        $repositories->register(\Base\Entity\Layout\Image::class, ThreadRepository::class);
        self::assertSame(ThreadRepository::class, $repositories->repositoryClass(\Base\Entity\Layout\Image::class));
        $images = $repositories->of(\Base\Entity\Layout\Image::class);
        self::assertInstanceOf(ThreadRepository::class, $images);
        self::assertSame(\Base\Entity\Layout\Image::class, $images->getClassName());
        self::assertSame(\Base\Entity\Thread::class, $repositories->implementing(\Base\Repository\Threads::class)->getClassName());
    }

    public function testAUnitOfWorkWithoutItsPersistenceStillGivesTheCoresRepositories(): void
    {
        // A unit of work made by hand (no EloquentPersistence remembers it): the behaviours' Repositories are the
        // core's EntityRepositories over EloquentFinders on its store, writing through it.
        EloquentBehaviours::ensureRegistered();
        $persistence = self::$persistence ?? throw new \LogicException('No persistence: setUp() did not run.');
        $unitOfWork = new EloquentUnitOfWork($persistence->store());
        $repositories = UnitOfWorks::repositories($unitOfWork);
        self::assertInstanceOf(EntityRepositories::class, $repositories);
        $threads = $repositories->of(\Base\Entity\Thread::class);
        self::assertInstanceOf(ThreadRepository::class, $threads);
        self::assertSame($unitOfWork, (new \ReflectionMethod($threads, 'unitOfWork'))->invoke($threads));
    }

    /**
     * The 15 methods once written per backend (the analytics, a redirection's hit), now written once in the core's
     * language, run on Eloquent; their parity with omnibridge/doctrine is the core's tests/Repository/AnalyticsParityTest,
     * tests/Harness/AnalyticsParityTest (SQLite) and tests/Site/AnalyticsParityTest (MySQL).
     */
    public function testTheAnalyticsAndTheHitRunOnEloquent(): void
    {
        $store = self::$persistence->store();
        $description = $store->mapping()->description;
        $tables = [];
        foreach ([\Base\Entity\Analytics\PageView::class, \Base\Entity\Analytics\PageVisit::class, \Base\Entity\Analytics\Visit::class, \Base\Entity\Layout\Redirection::class] as $class) {
            $table = $description->entities[$class]->table;
            $tables[$table] = $description->tables[$table];
        }
        (new SchemaCreator($store->connection()->getPdo()))->create(new Description([], $tables));

        $repositories = self::$persistence->repositories();
        $views = $repositories->implementing(\Base\Repository\Analytics\PageViews::class);
        $pageVisits = $repositories->implementing(\Base\Repository\Analytics\PageVisits::class);
        $visits = $repositories->implementing(\Base\Repository\Analytics\Visits::class);
        $utc = new \DateTimeZone('UTC');
        $tenPast = new \DateTimeImmutable('2026-11-05 10:20:00', $utc);
        $yesterday = new \DateTimeImmutable('2026-11-04 23:59:00', $utc);
        $since = new \DateTimeImmutable('2026-11-01 00:00:00', $utc);

        $views->incrementView('/a', $tenPast);
        $views->incrementView('/a', $tenPast->modify('+30 minutes'));
        $views->incrementView('/a', $tenPast, \Base\Entity\Analytics\PageView::SOURCE_BOT);
        $views->incrementView('/b', $yesterday);
        self::assertSame(['2026-11-04' => 1, '2026-11-05' => 3], $views->dailyBreakdown($since));
        self::assertSame(['2026-11-05' => 3], $views->dailyBreakdown($since, '/a'));
        self::assertSame(['2026-11-04' => 1], $views->dailyBreakdown($since, ['/b', '/c']));
        self::assertSame([], $views->dailyBreakdown($since, []));
        self::assertSame(['2026-11-04' => ['human' => 1, 'bot' => 0, 'ai' => 0], '2026-11-05' => ['human' => 2, 'bot' => 1, 'ai' => 0]], $views->dailyBreakdownBySource($since));
        self::assertSame(['2026-11-04 23:00:00' => 1, '2026-11-05 10:00:00' => 3], $views->hourlyBreakdown($since));
        self::assertSame(['2026-11-05 10:00:00' => ['human' => 2, 'bot' => 1, 'ai' => 0]], $views->hourlyBreakdownBySource($tenPast->setTime(0, 0), '/a'));
        self::assertSame(4, $views->countViews());

        $pageVisits->recordPresence('/a', 'visitor', 'v1', $tenPast);
        $pageVisits->recordPresence('/a', 'visitor', 'v1', $tenPast->modify('+5 minutes'));
        $pageVisits->recordPresence('/b', 'visitor', 'v1', $yesterday);
        $pageVisits->recordPresence('/a', 'visitor', 'v2', $yesterday);
        self::assertSame(3, $pageVisits->count());
        self::assertSame(3, $pageVisits->countUnique());
        self::assertSame(2, $pageVisits->countUnique($since, '/a'));
        self::assertSame(0, $pageVisits->countUnique($since, []));
        self::assertSame(['2026-11-04' => 2, '2026-11-05' => 1], $pageVisits->dailyBreakdown($since));
        self::assertSame(['2026-11-04 23:00:00' => 1], $pageVisits->hourlyBreakdown($since, ['/b']));

        $visits->recordPresence('visitor', 'v1', $yesterday);
        $visits->recordPresence('visitor', 'v1', $yesterday->modify('-10 minutes'));
        $visits->recordPresence('visitor', 'v2', $yesterday);
        $visits->recordPresence('visitor', 'v1', $tenPast);
        self::assertSame(3, $visits->count());
        self::assertSame(['previous' => 2, 'returning' => 1], $visits->returning('visitor', $since, $tenPast->setTime(0, 0)));
        self::assertSame(['2026-11-04' => 2, '2026-11-05' => 1], $visits->dailyBreakdown('visitor', $since));
        self::assertSame(['2026-11-04 23:00:00' => 2, '2026-11-05 10:00:00' => 1], $visits->hourlyBreakdown('visitor', $since));

        $redirections = $repositories->implementing(\Base\Repository\Layout\Redirections::class);
        $redirection = new \Base\Entity\Layout\Redirection('/old', '/new');
        $redirections->persist($redirection, true);
        $redirections->hit($redirection);
        $redirections->hit($redirection);
        self::assertSame(0, $redirection->getHits(), 'the entity read before is left as it was, as a DQL UPDATE leaves it');
        $store->clear();
        $again = $redirections->find($redirection->getId());
        self::assertSame(2, $again->getHits());
        self::assertNotNull($again->getLastHitAt());
    }

    /** The repositories' writes go through the unit of work (step F7c): ScopedWeeks::save()/forget(), WeekDays::save(). */
    public function testTheHoursWritesGoThroughTheUnitOfWork(): void
    {
        $store = self::$persistence->store();
        $description = $store->mapping()->description;
        $tables = [];
        foreach ([\Base\Entity\Hours\ScopedWeek::class, \Base\Entity\Hours\WeekDayHours::class] as $class) {
            $table = $description->entities[$class]->table;
            $tables[$table] = $description->tables[$table];
        }
        (new SchemaCreator($store->connection()->getPdo()))->create(new Description([], $tables));

        $weeks = self::$persistence->repositories()->implementing(\Base\Repository\Hours\ScopedWeeks::class);
        $week = [1 => [['09:00', '12:00']], 3 => [['14:00', '18:00']]];
        $saved = $weeks->save('office', $week);
        self::assertNotNull($saved->getId());
        $store->clear();
        self::assertSame((new \Base\Entity\Hours\ScopedWeek('office', $week))->getWeek(), $weeks->week('office'));
        $weeks->save('office', [2 => [['10:00', '11:00']]]);
        $store->clear();
        self::assertSame((new \Base\Entity\Hours\ScopedWeek('office', [2 => [['10:00', '11:00']]]))->getWeek(), $weeks->week('office'));
        $weeks->forget('office');
        $store->clear();
        self::assertNull($weeks->ofScope('office'));

        $days = self::$persistence->repositories()->implementing(\Base\Repository\Hours\WeekDays::class);
        $days->save([1 => [['08:00', '17:00']], 7 => []]);
        $store->clear();
        self::assertCount(7, $days->findAll());
        self::assertSame([['08:00', '17:00']], $days->week()[1]);

        // A repository made without a unit of work refuses to write.
        $this->expectException(UnsupportedPersistence::class);
        (new ServiceEntityRepository(new EloquentFinders($store), \Base\Entity\Hours\ScopedWeek::class))->persist(new \Base\Entity\Hours\ScopedWeek('x', []));
    }
}
