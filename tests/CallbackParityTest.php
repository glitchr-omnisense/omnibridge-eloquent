<?php

namespace Omnibridge\Eloquent\Tests;

use Doctrine\DBAL\DriverManager;
use Omnibridge\Doctrine\EntityManagers;
use Omnibridge\Eloquent\Casts;
use Omnibridge\Eloquent\Connections;
use Omnibridge\Eloquent\EloquentUnitOfWork;
use Omnibridge\Eloquent\EntityStore;
use Omnibridge\Eloquent\Mapping;
use Omnibridge\Eloquent\Tests\Fixtures\Callbacks\Stamped;
use Omnibridge\Eloquent\Tests\Fixtures\Callbacks\Ticket;
use Omnibridge\Eloquent\Tests\Support\DoctrineSide;
use Omnibridge\Persistence\UnitOfWork;
use Omnibridge\Schema\Builder;
use Omnibridge\Schema\Database\SchemaCreator;
use Omnibridge\Schema\Description;
use PHPUnit\Framework\TestCase;

/**
 * The entities' lifecycle callbacks (#[HasLifecycleCallbacks] with #[PrePersist] ... #[PostLoad], 2.2.0) on both
 * backends: the same calls through Doctrine's entity manager and through omnibridge/eloquent's unit of work call the
 * same callbacks in the same order - the mapped superclass's before the entity's, preFlush for each entity whose
 * change set is computed, preUpdate before the update, a field it changes written though the change set did not hold
 * it (recomputed after the callbacks, as Doctrine does), postLoad after a read - and leave the same rows.
 */
final class CallbackParityTest extends TestCase
{
    private Description $description;

    /** @var array<string, array{unitOfWork: UnitOfWork, pdo: \PDO, find: \Closure(int): ?Ticket, clear: \Closure(): void}> */
    private array $sides = [];

    protected function setUp(): void
    {
        $this->description = (new Builder())->build([Stamped::class, Ticket::class]);

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $native = $connection->getNativeConnection();
        \assert($native instanceof \PDO);
        (new SchemaCreator($native))->create($this->description);
        $entityManager = EntityManagers::create($connection, [__DIR__.'/Fixtures/Callbacks']);
        $this->sides['doctrine'] = [
            'unitOfWork' => new DoctrineSide($entityManager),
            'pdo' => $native,
            'find' => static fn (int $id): ?Ticket => $entityManager->find(Ticket::class, $id),
            'clear' => static function () use ($entityManager): void {
                $entityManager->clear();
            },
        ];

        $pdo = new \PDO('sqlite::memory:');
        (new SchemaCreator($pdo))->create($this->description);
        $store = new EntityStore(Connections::pdo($pdo), new Mapping($this->description), Casts::utc());
        $this->sides['eloquent'] = [
            'unitOfWork' => new EloquentUnitOfWork($store),
            'pdo' => $pdo,
            'find' => static fn (int $id): ?Ticket => $store->find(Ticket::class, $id),
            'clear' => static function () use ($store): void {
                $store->clear();
            },
        ];
    }

    public function testTheDescriptionCarriesTheCallbacks(): void
    {
        self::assertSame([
            'prePersist' => ['created', 'touch'],
            'preUpdate' => ['touch'],
            'preFlush' => ['flushing'],
            'postPersist' => ['after'],
            'postUpdate' => ['after'],
            'preRemove' => ['after'],
            'postRemove' => ['after'],
            'postLoad' => ['after'],
        ], $this->description->entity(Ticket::class)->callbacks);
    }

    public function testTheCallbacksRunAtDoctrinesMoments(): void
    {
        $calls = [];
        $rows = [];
        foreach ($this->sides as $name => $side) {
            Stamped::$calls = [];
            $unitOfWork = $side['unitOfWork'];

            $first = new Ticket('first');
            $unitOfWork->persist($first);
            $unitOfWork->persist(new Ticket('second'));
            $unitOfWork->flush();
            $id = (int) $first->getId();
            ($side['clear'])();

            $ticket = ($side['find'])($id);
            self::assertNotNull($ticket);
            $ticket->setTitle('first, again');
            $unitOfWork->flush();
            // Nothing changed by the application: its preFlush callback changes a field, so the entity is updated again.
            $unitOfWork->flush();
            ($side['clear'])();

            $found = ($side['find'])($id);
            self::assertNotNull($found);
            $unitOfWork->remove($found);
            $unitOfWork->flush();

            $calls[$name] = Stamped::$calls;
            $statement = $side['pdo']->query('SELECT * FROM ticket ORDER BY id');
            self::assertNotFalse($statement);
            $rows[$name] = $statement->fetchAll(\PDO::FETCH_ASSOC);
        }

        self::assertSame($calls['doctrine'], $calls['eloquent']);
        self::assertSame($rows['doctrine'], $rows['eloquent']);
        self::assertContains('touch first, again 2', $calls['eloquent'], 'the PreUpdate callback ran');
        $normalised = [];
        foreach ($rows['eloquent'] as $row) {
            $normalised[] = array_map(static fn (mixed $value): mixed => is_numeric($value) ? (int) $value : $value, (array) $row);
        }
        self::assertSame([['createdBy' => 'callback', 'id' => 2, 'title' => 'second', 'revision' => 1, 'flushes' => 1]], $normalised);
    }
}
