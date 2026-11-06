<?php

namespace Omnibridge\Eloquent\Tests;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Omnibridge\Doctrine\EntityManagers;
use Omnibridge\Eloquent\Casts;
use Omnibridge\Eloquent\Connections;
use Omnibridge\Eloquent\EloquentUnitOfWork;
use Omnibridge\Eloquent\EntityStore;
use Omnibridge\Eloquent\InvalidPersistence;
use Omnibridge\Eloquent\LazyCollection;
use Omnibridge\Eloquent\Mapping;
use Omnibridge\Eloquent\Tests\Fixtures\Entity\Animal;
use Omnibridge\Eloquent\Tests\Fixtures\Entity\Article;
use Omnibridge\Eloquent\Tests\Fixtures\Entity\Cat;
use Omnibridge\Eloquent\Tests\Fixtures\Entity\Dog;
use Omnibridge\Eloquent\Tests\Fixtures\Entity\Page;
use Omnibridge\Eloquent\Tests\Fixtures\Entity\Profile;
use Omnibridge\Eloquent\Tests\Fixtures\Entity\Setting;
use Omnibridge\Eloquent\Tests\Fixtures\Entity\Tag;
use Omnibridge\Eloquent\Tests\Fixtures\Entity\Thread;
use Omnibridge\Eloquent\Tests\Fixtures\Entity\Translation;
use Omnibridge\Eloquent\Tests\Fixtures\Entity\User;
use Omnibridge\Eloquent\Tests\Support\DoctrineSide;
use Omnibridge\Eloquent\UnsupportedPersistence;
use Omnibridge\Persistence\Lifecycle;
use Omnibridge\Persistence\UnitOfWork;
use Omnibridge\Schema\Builder;
use Omnibridge\Schema\Database\SchemaCreator;
use Omnibridge\Schema\Description;
use PHPUnit\Framework\TestCase;

/**
 * The writes on both backends (plan "omnibase agnostic", step F7c): the same calls on the same entities, through
 * Doctrine's entity manager on one SQLite database and through omnibridge/eloquent's unit of work on another (both
 * created from the fixtures' description, with no ORM), leave the same rows - every column of every table, the
 * identifiers the databases gave included, so the insert order is the same - and dispatch the same lifecycle
 * events in the same order (each one's entity, and a PreUpdate's changed fields).
 *
 * The scenarios: a graph inserted (a joined and a single-table hierarchy with their discriminators, an identifier
 * the application gives, a many-to-many's join rows, cascades through a one-to-many and an inverse one-to-one,
 * mandatory and nullable foreign keys in commit order); fields, to-one associations, join rows added, taken out
 * and replaced, an orphan removed; deletes cascaded and ordered; a nullable cycle broken by an extra update; a
 * listener's PreUpdate new value and OnFlush insertion; a new entity reached without cascade refused; a
 * transaction rolled back; the states (isNew, contains) along the way.
 */
final class WriteParityTest extends TestCase
{
    private const CLASSES = [Thread::class, Article::class, Page::class, Tag::class, Translation::class, User::class, Profile::class, Animal::class, Cat::class, Dog::class, Setting::class];

    private Description $description;

    private EntityManager $entityManager;

    private \PDO $doctrinePdo;

    private EntityStore $store;

    private EloquentUnitOfWork $unitOfWork;

    private \PDO $eloquentPdo;

    /** @var array{doctrine: list<string>, eloquent: list<string>} */
    private array $events = ['doctrine' => [], 'eloquent' => []];

    protected function setUp(): void
    {
        $this->description = (new Builder())->build(self::CLASSES);

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->doctrinePdo = $connection->getNativeConnection();
        (new SchemaCreator($this->doctrinePdo))->create($this->description);
        $this->entityManager = EntityManagers::create($connection, [__DIR__.'/Fixtures/Entity']);
        $recorder = new class($this->events['doctrine']) {
            public function __construct(private array &$events)
            {
            }

            public function __call(string $event, array $arguments): void
            {
                $args = $arguments[0];
                $entity = method_exists($args, 'getObject') ? $args->getObject() : null;
                $changes = $args instanceof PreUpdateEventArgs ? array_keys($args->getEntityChangeSet()) : null;
                $this->events[] = WriteParityTest::event(ucfirst($event), $entity, $changes);
            }
        };
        $this->entityManager->getEventManager()->addEventListener([Events::prePersist, Events::postPersist, Events::preUpdate, Events::postUpdate, Events::preRemove, Events::postRemove, Events::postLoad, Events::preFlush, Events::onFlush, Events::postFlush], $recorder);

        $this->eloquentPdo = new \PDO('sqlite::memory:');
        (new SchemaCreator($this->eloquentPdo))->create($this->description);
        $this->store = new EntityStore(Connections::pdo($this->eloquentPdo), new Mapping($this->description), Casts::utc());
        $this->unitOfWork = new EloquentUnitOfWork($this->store);
        $this->unitOfWork->listen(function (object $event): void {
            $changes = $event instanceof Lifecycle\PreUpdate ? array_keys($event->changes()) : null;
            $this->events['eloquent'][] = self::event((new \ReflectionClass($event))->getShortName(), $event instanceof Lifecycle\EntityEvent ? $event->entity : null, $changes);
        });
    }

    /** An event as both backends name it: its moment, its entity's class and label, a PreUpdate's changed fields. */
    public static function event(string $moment, ?object $entity, ?array $changes = null): string
    {
        if (null === $entity) {
            return $moment;
        }
        $label = [];
        foreach (['title', 'name', 'email', 'locale', 'bio'] as $property) {
            for ($class = $entity::class; false !== $class; $class = get_parent_class($class)) {
                if (property_exists($class, $property) && (new \ReflectionProperty($class, $property))->getDeclaringClass()->getName() === $class) {
                    $reflection = new \ReflectionProperty($class, $property);
                    $label[] = $reflection->isInitialized($entity) ? (string) $reflection->getRawValue($entity) : '?';
                    break;
                }
            }
        }
        if (null !== $changes) {
            sort($changes);
        }

        return $moment.' '.(new \ReflectionClass($entity))->getShortName().'('.implode(',', $label).')'.(null === $changes ? '' : ' ['.implode(',', $changes).']');
    }

    public function testAGraphIsInsertedInCommitOrder(): void
    {
        $this->both(function (UnitOfWork $unitOfWork): void {
            $this->graph($unitOfWork);
        });
    }

    public function testFieldsAssociationsAndJoinRowsAreUpdated(): void
    {
        $this->both(function (UnitOfWork $unitOfWork, \Closure $find, \Closure $clear): void {
            $ids = $this->graph($unitOfWork);
            $clear();

            $article = $find(Article::class, $ids['article']);
            $article->setTitle('Zanzibar, again');
            $bob = new User('bob@example.org');
            $unitOfWork->persist($bob);
            $article->setAuthor($bob);
            $tags = [];
            foreach ($article->getTags() as $tag) {
                $tags[$tag->getName()] = $tag;
            }
            $article->removeTag($tags['news']);
            $food = new Tag('food');
            $unitOfWork->persist($food);
            $article->addTag($food);
            $article->removeTranslation('en');

            $page = $find(Page::class, $ids['page']);
            $page->setTags(new ArrayCollection([$tags['travel'], $food]));
            $page->setPosition(9);

            $setting = $find(Setting::class, 'theme');
            $setting->setValue('light');
            $find(Thread::class, $ids['root']);

            $unitOfWork->flush();

            // A second flush with nothing changed writes nothing.
            $unitOfWork->flush();
        });
    }

    public function testDeletesCascadeAndRunInDependencyOrder(): void
    {
        $this->both(function (UnitOfWork $unitOfWork, \Closure $find, \Closure $clear): void {
            $ids = $this->graph($unitOfWork);
            $clear();

            $unitOfWork->remove($find(Article::class, $ids['article']));
            $unitOfWork->remove($find(Animal::class, $ids['cat']));
            $setting = $find(Setting::class, 'theme');
            $unitOfWork->remove($setting);
            $user = $find(User::class, $ids['alice']);
            $unitOfWork->remove($user->getProfile());
            $unitOfWork->flush();

            // A removed entity persisted again is kept.
            $dog = $find(Animal::class, $ids['dog']);
            $unitOfWork->remove($dog);
            $unitOfWork->persist($dog);
            $unitOfWork->flush();
        });
    }

    public function testANullableCycleIsBrokenByAnExtraUpdate(): void
    {
        $this->both(function (UnitOfWork $unitOfWork): void {
            $a = new Thread('A');
            $b = (new Thread('B'))->setParent($a);
            $a->setParent($b);
            $unitOfWork->persist($a);
            $unitOfWork->persist($b);
            $unitOfWork->flush();
        });
    }

    public function testAListenersNewValueAndOnFlushInsertionAreWritten(): void
    {
        $this->entityManager->getEventManager()->addEventListener([Events::preUpdate, Events::onFlush], new class {
            public function preUpdate(PreUpdateEventArgs $args): void
            {
                if ($args->getObject() instanceof Thread && $args->hasChangedField('title')) {
                    $args->setNewValue('title', $args->getNewValue('title').' (edited)');
                }
            }

            public function onFlush(OnFlushEventArgs $args): void
            {
                $entityManager = $args->getObjectManager();
                foreach ($entityManager->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
                    if ($entity instanceof User) {
                        $tag = new Tag('for '.$entity->getEmail());
                        $entityManager->persist($tag);
                        $entityManager->getUnitOfWork()->computeChangeSet($entityManager->getClassMetadata(Tag::class), $tag);
                    }
                }
            }
        });
        $this->unitOfWork->listen(function (object $event): void {
            if ($event instanceof Lifecycle\PreUpdate && $event->entity instanceof Thread && $event->hasChanged('title')) {
                $event->setNewValue('title', $event->changes()['title'][1].' (edited)');
            }
            if ($event instanceof Lifecycle\OnFlush) {
                foreach ($this->unitOfWork->scheduledInsertions() as $entity) {
                    if ($entity instanceof User) {
                        $tag = new Tag('for '.$entity->getEmail());
                        $event->unitOfWork->persist($tag);
                        $event->unitOfWork->recompute($tag);
                    }
                }
            }
        });

        $this->both(function (UnitOfWork $unitOfWork, \Closure $find, \Closure $clear): void {
            $ids = $this->graph($unitOfWork);
            $clear();
            $root = $find(Thread::class, $ids['root']);
            $root->setTitle('Root, renamed');
            $unitOfWork->flush();
        });
    }

    public function testANewEntityReachedWithoutCascadeIsRefused(): void
    {
        $this->both(function (UnitOfWork $unitOfWork): void {
            $thread = (new Thread('Orphan'))->setAuthor(new User('nobody@example.org'));
            $unitOfWork->persist($thread);
            try {
                $unitOfWork->flush();
                self::fail('a new author without cascade was written');
            } catch (\InvalidArgumentException $refused) {
                self::assertStringContainsString('cascade persist', $refused->getMessage());
            }
        }, false);
    }

    public function testATransactionThatThrowsWritesNothing(): void
    {
        $this->both(function (UnitOfWork $unitOfWork): void {
            try {
                $unitOfWork->transactional(function (UnitOfWork $work): void {
                    $work->persist(new User('rolled@example.org'));
                    $work->flush();

                    throw new \RuntimeException('no');
                });
                self::fail('the work did not throw');
            } catch (\RuntimeException $failure) {
                self::assertSame('no', $failure->getMessage());
            }
        }, false);
    }

    public function testTheStatesAlongTheWayAreDoctrines(): void
    {
        $states = [];
        $this->both(function (UnitOfWork $unitOfWork, \Closure $find, \Closure $clear, string $backend) use (&$states): void {
            $user = new User('state@example.org');
            $record = static function (string $step) use (&$states, $backend, $unitOfWork, $user): void {
                $states[$backend][] = $step.': new='.json_encode($unitOfWork->isNew($user)).' contains='.json_encode($unitOfWork->contains($user)).' id='.json_encode($unitOfWork->identifier($user));
            };
            $record('made');
            $unitOfWork->persist($user);
            $record('persisted');
            $unitOfWork->flush();
            $record('flushed');
            $unitOfWork->remove($user);
            $record('removed');
            $unitOfWork->flush();
            $record('deleted');
        }, false);
        self::assertSame($states['doctrine'], $states['eloquent']);
    }

    public function testSnapshotsAndChangesDuringAFlush(): void
    {
        $user = (new User('snap@example.org'))->setScore(2.0);
        $this->unitOfWork->persist($user);
        $this->unitOfWork->flush();
        self::assertSame('snap@example.org', $this->unitOfWork->original($user)['email']);
        $user->setEmail('snapped@example.org');
        $seen = null;
        $this->unitOfWork->listen(function (object $event) use ($user, &$seen): void {
            if ($event instanceof Lifecycle\OnFlush) {
                $seen = $this->unitOfWork->changes($user);
            }
        });
        $this->unitOfWork->flush();
        self::assertSame(['email' => ['snap@example.org', 'snapped@example.org']], $seen);
        self::assertSame('snapped@example.org', $this->unitOfWork->original($user)['email']);
        self::assertSame([], $this->unitOfWork->changes($user), 'no change set outside a flush');
    }

    public function testACollectionTheApplicationMadeBecomesALazyCollection(): void
    {
        $thread = (new Thread('Lazy'))->addTag($tag = new Tag('lazy'));
        $this->unitOfWork->persist($tag);
        $this->unitOfWork->persist($thread);
        $this->unitOfWork->flush();
        self::assertInstanceOf(LazyCollection::class, $thread->getTags());
        self::assertSame([$tag], $thread->getTags()->toArray());
    }

    public function testAFailedFlushClosesTheUnitOfWork(): void
    {
        $this->unitOfWork->persist(new Setting('dup', $owner = new User('dup@example.org')));
        $this->unitOfWork->persist($owner);
        $this->unitOfWork->flush();
        $this->store->clear();
        $this->unitOfWork->persist(new Setting('dup', $this->store->find(User::class, $owner->getId())));
        try {
            $this->unitOfWork->flush();
            self::fail('a duplicate key was written');
        } catch (\Illuminate\Database\QueryException) {
        }
        $this->expectException(UnsupportedPersistence::class);
        $this->unitOfWork->persist(new User('after@example.org'));
    }

    /**
     * The graph the scenarios start from, persisted in an order its foreign keys do not follow.
     *
     * @return array<string, mixed> the identifiers
     */
    private function graph(UnitOfWork $unitOfWork): array
    {
        $alice = (new User('alice@example.org'))->setScore(4.5)->setBalance('12.50')->setVisits(7);
        $alice->setProfile(new Profile($alice, 'Writes'));
        $news = new Tag('news');
        $travel = new Tag('travel');
        $root = new Thread('Root');
        $article = (new Article('Zanzibar'))->setAuthor($alice)->setParent($root)->setBody('A long text')->setMeta(['lang' => 'fr', 'n' => [1, 2]])->setKeywords(['sea', 'spice'])->setFeatured(true)->setPublishedAt(new \DateTimeImmutable('2026-10-01 08:30:00'));
        $article->addTag($news)->addTag($travel);
        $article->addTranslation(new Translation($article, 'fr', 'Zanzibar (fr)'))->addTranslation(new Translation($article, 'en', 'Zanzibar (en)'));
        $page = (new Page('About'))->setAuthor($alice)->setPosition(3)->setDay(new \DateTime('2026-01-02'))->addTag($travel);
        $cat = new Cat('Tom', $alice);
        $dog = (new Dog('Rex', $alice))->setTrained(true);
        $setting = new Setting('theme', $alice, 'dark');
        foreach ([$setting, $cat, $dog, $page, $article, $root, $news, $travel, $alice, new Animal('Nemo')] as $entity) {
            $unitOfWork->persist($entity);
        }
        $unitOfWork->flush();

        return ['alice' => $alice->getId(), 'article' => $article->getId(), 'page' => $page->getId(), 'root' => $root->getId(), 'cat' => $cat->getId(), 'dog' => $dog->getId()];
    }

    /**
     * The scenario on Doctrine, then on Eloquent; the rows of every table and the events compared.
     *
     * @param \Closure(UnitOfWork, \Closure(string, mixed): ?object, \Closure(): void, string): void $scenario
     */
    private function both(\Closure $scenario, bool $writes = true): void
    {
        $scenario(new DoctrineSide($this->entityManager), fn (string $class, mixed $id): ?object => $this->entityManager->find($class, $id), fn () => $this->entityManager->clear(), 'doctrine');
        $scenario($this->unitOfWork, fn (string $class, mixed $id): ?object => $this->store->find($class, $id), fn () => $this->store->clear(), 'eloquent');

        $doctrine = $this->rows($this->doctrinePdo);
        $eloquent = $this->rows($this->eloquentPdo);
        self::assertSame($doctrine, $eloquent);
        if ($writes) {
            self::assertNotSame([], array_filter($doctrine), 'the scenario wrote rows');
        } else {
            self::assertSame([], array_filter($doctrine), 'the scenario wrote no row');
        }
        self::assertSame($this->events['doctrine'], $this->events['eloquent']);
    }

    /** @return array<string, list<array<string, mixed>>> every table's rows, sorted */
    private function rows(\PDO $pdo): array
    {
        $tables = [];
        foreach (array_keys($this->description->tables) as $table) {
            $rows = $pdo->query('SELECT * FROM "'.$table.'"')->fetchAll(\PDO::FETCH_ASSOC);
            usort($rows, static fn (array $a, array $b): int => strcmp(json_encode($a), json_encode($b)));
            $tables[$table] = $rows;
        }

        return $tables;
    }
}
