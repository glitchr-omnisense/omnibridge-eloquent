<?php

namespace Omnibridge\Eloquent\Tests\Omnibase;

use Base\Database\Repository\BackendOnly;
use Base\Database\Repository\Entities;
use Base\Database\Repository\UnsupportedQueryException;
use Base\Repository\Aliases;
use Omnibridge\Eloquent\Connections;
use Omnibridge\Eloquent\Omnibase\EloquentEntities;
use Omnibridge\Eloquent\Omnibase\EloquentPersistence;
use Omnibridge\Eloquent\Omnibase\Repository\ThreadRepository;
use Omnibridge\Eloquent\UnsupportedPersistence;
use Omnibridge\Schema\Database\SchemaCreator;
use Omnibridge\Schema\Description;
use PHPUnit\Framework\TestCase;

/**
 * The core's repository interfaces on Eloquent: each implemented (Aliases::INTERFACES, the core's entities described
 * with no Doctrine), a subclass's entity given its parent's repository, the methods declared #[BackendOnly('doctrine')]
 * refused aloud with UnsupportedQueryException; the writes through the unit of work.
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
        $interfaces = array_filter(array_keys(Aliases::INTERFACES), static fn (string $interface): bool => is_subclass_of($interface, Entities::class));
        self::assertCount(28, $interfaces, 'the 28 repository interfaces (the 29th, Repositories, is EloquentRepositories)');
        foreach ($interfaces as $interface) {
            $repository = $repositories->implementing($interface);
            self::assertInstanceOf($interface, $repository);
            self::assertInstanceOf(EloquentEntities::class, $repository);
        }
        self::assertInstanceOf(\Base\Database\Repository\Repositories::class, $repositories);
    }

    public function testASubclassGetsItsParentsRepositoryAndAnyOtherClassThePlainOne(): void
    {
        $repositories = self::$persistence->repositories();
        self::assertSame(ThreadRepository::class, $repositories->repositoryClass(\Base\Entity\Thread::class));
        self::assertSame(EloquentEntities::class, $repositories->repositoryClass(\Base\Entity\Layout\Image::class));
        $repositories->register(\Base\Entity\Layout\Image::class, ThreadRepository::class);
        self::assertSame(ThreadRepository::class, $repositories->repositoryClass(\Base\Entity\Layout\Image::class));
    }

    public function testTheBackendOnlyMethodsAreRefusedAloud(): void
    {
        $repositories = self::$persistence->repositories();
        $declared = BackendOnly::declared(array_keys(Aliases::INTERFACES));
        self::assertCount(14, $declared);
        $now = new \DateTimeImmutable();
        $arguments = [
            'incrementView' => ['/', $now], 'dailyBreakdown' => [$now], 'dailyBreakdownBySource' => [$now], 'hourlyBreakdown' => [$now],
            'hourlyBreakdownBySource' => [$now], 'countUnique' => [], 'returning' => ['user', $now, $now],
        ];
        foreach ($declared as $name => $declaration) {
            [$interface, $method] = explode('::', $name);
            $repository = $repositories->implementing($interface);
            $given = match (true) {
                \Base\Repository\Analytics\Visits::class === $interface && 'recordPresence' === $method => ['user', '1', $now],
                \Base\Repository\Analytics\Visits::class === $interface && \in_array($method, ['dailyBreakdown', 'hourlyBreakdown'], true) => ['user', $now],
                \Base\Repository\Analytics\Visits::class === $interface && 'countUnique' === $method => ['user'],
                \Base\Repository\Analytics\PageVisits::class === $interface && 'recordPresence' === $method => ['/', 'user', '1', $now],
                \Base\Repository\Layout\Redirections::class === $interface => [new \Base\Entity\Layout\Redirection('/a', '/b')],
                default => $arguments[$method] ?? [],
            };
            try {
                $repository->{$method}(...$given);
                self::fail($name.' refused');
            } catch (UnsupportedQueryException $refused) {
                self::assertStringContainsString('"eloquent"', $refused->getMessage(), $name);
                self::assertStringContainsString('doctrine', $refused->getMessage(), $name);
            }
        }
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
        (new EloquentEntities($store, \Base\Entity\Hours\ScopedWeek::class))->persist(new \Base\Entity\Hours\ScopedWeek('x', []));
    }
}
