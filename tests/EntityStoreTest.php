<?php

namespace Omnibridge\Eloquent\Tests;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Omnibridge\Doctrine\EntityManagers;
use Omnibridge\Eloquent\Casts;
use Omnibridge\Eloquent\Connections;
use Omnibridge\Eloquent\EloquentUnitOfWork;
use Omnibridge\Eloquent\EntityNotFound;
use Omnibridge\Eloquent\EntityStore;
use Omnibridge\Eloquent\LazyCollection;
use Omnibridge\Eloquent\Mapping;
use Omnibridge\Eloquent\Tests\Fixtures\Entity\Animal;
use Omnibridge\Eloquent\Tests\Fixtures\Entity\Article;
use Omnibridge\Eloquent\Tests\Fixtures\Entity\Cat;
use Omnibridge\Eloquent\Tests\Fixtures\Entity\Dog;
use Omnibridge\Eloquent\Tests\Fixtures\Entity\Page;
use Omnibridge\Eloquent\Tests\Fixtures\Entity\Profile;
use Omnibridge\Eloquent\Tests\Fixtures\Entity\Tag;
use Omnibridge\Eloquent\Tests\Fixtures\Entity\Thread;
use Omnibridge\Eloquent\Tests\Fixtures\Entity\Translation;
use Omnibridge\Eloquent\Tests\Fixtures\Entity\User;
use Omnibridge\Eloquent\Tests\Support\Normaliser;
use Omnibridge\Eloquent\UnsupportedPersistence;
use Omnibridge\Schema\Builder;
use Omnibridge\Schema\Database\SchemaCreator;
use Omnibridge\Schema\Description;
use PHPUnit\Framework\TestCase;

/**
 * The store on the fixtures' tables (SQLite in memory, created from their description with no ORM), the rows
 * written by Doctrine: each entity read by id is the one Doctrine reads (Normaliser), through a joined and a
 * single-table hierarchy, a to-one association as a ghost or read at once, an inverse one-to-one, to-many
 * associations ordered, indexed, owning and inverse; the ghosts and collections read nothing until used; one
 * object per row; writes refused.
 */
final class EntityStoreTest extends TestCase
{
    private const CLASSES = [Thread::class, Article::class, Page::class, Tag::class, Translation::class, User::class, Profile::class, Animal::class, Cat::class, Dog::class];

    private EntityManager $entityManager;

    private EntityStore $store;

    private Description $description;

    /** @var array<string, list<int>> */
    private array $ids = [];

    protected function setUp(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $pdo = $connection->getNativeConnection();
        $this->description = (new Builder())->build(self::CLASSES);
        (new SchemaCreator($pdo))->create($this->description);
        $this->entityManager = EntityManagers::create($connection, [__DIR__.'/Fixtures/Entity']);
        $this->store = new EntityStore(Connections::pdo($pdo), new Mapping($this->description), Casts::utc());

        $alice = (new User('alice@example.org'))->setScore(4.5)->setBalance('12.50')->setVisits(PHP_INT_MAX);
        $bob = new User('bob@example.org');
        $news = new Tag('news');
        $travel = new Tag('travel');
        $root = new Thread('Root');
        $article = (new Article('Zanzibar'))->setAuthor($alice)->setParent($root)->setBody('A long text')->setMeta(['lang' => 'fr', 'n' => [1, 2]])->setKeywords(['sea', 'spice'])->setFeatured(true)->setPublishedAt(new \DateTimeImmutable('2026-10-01 08:30:00'));
        $article->addTag($news)->addTag($travel);
        $second = (new Article('Avignon'))->setAuthor($alice)->addTag($travel);
        $page = (new Page('About'))->setAuthor($bob)->setPosition(3)->setDay(new \DateTime('2026-01-02'));
        $entities = [$alice, $bob, $news, $travel, $root, $article, $second, $page,
            new Translation($article, 'fr', 'Zanzibar (fr)'), new Translation($article, 'en', 'Zanzibar (en)'),
            new Profile($alice, 'Writes'), new Cat('Tom', $alice), (new Dog('Rex', $bob))->setTrained(true), new Animal('Nemo')];
        foreach ($entities as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        foreach ($entities as $entity) {
            $this->ids[$entity::class][] = $entity->getId();
        }
        $this->entityManager->clear();
    }

    public function testEachEntityReadByIdIsDoctrines(): void
    {
        $normaliser = new Normaliser($this->description);
        $compared = 0;
        foreach (self::CLASSES as $class) {
            foreach ($this->ids[$class] ?? [] as $id) {
                $doctrine = $this->entityManager->find($class, $id);
                $eloquent = $this->store->find($class, $id);
                self::assertNotNull($eloquent, $class.' '.$id);
                self::assertSame($normaliser->entity($doctrine), $normaliser->entity($eloquent), $class.' '.$id);
                ++$compared;
            }
            // By the hierarchy's root: the row's own class.
            foreach ($this->ids[$class] ?? [] as $id) {
                $root = $this->description->entities[$class]->root ?? $class;
                self::assertSame($normaliser->entity($this->entityManager->find($root, $id)), $normaliser->entity($this->store->find($root, $id)));
            }
        }
        self::assertSame(14, $compared);
    }

    public function testAToOneAssociationIsAGhostUntilAFieldIsRead(): void
    {
        $article = $this->store->find(Article::class, $this->ids[Article::class][0]);
        $queries = $this->store->queries();
        $author = $article->getAuthor();
        self::assertInstanceOf(User::class, $author);
        self::assertTrue($this->store->isUninitialized($author));
        self::assertSame($this->ids[User::class][0], $author->getId());
        self::assertSame($queries, $this->store->queries(), 'the identifier is read without the row');
        self::assertSame('alice@example.org', $author->getEmail());
        self::assertFalse($this->store->isUninitialized($author));
        self::assertGreaterThan($queries, $this->store->queries());
        self::assertSame($author, $this->store->find(User::class, $this->ids[User::class][0]), 'one object per row');
    }

    public function testAnAssociationToAClassWithSubclassesIsReadAtOnce(): void
    {
        $article = $this->store->find(Article::class, $this->ids[Article::class][0]);
        $parent = $article->getParent();
        self::assertInstanceOf(Thread::class, $parent);
        self::assertFalse($this->store->isUninitialized($parent));
        self::assertSame('Root', $parent->getTitle());
        self::assertSame(Thread::class, $parent::class);
        self::assertSame($this->ids[Profile::class][0], $this->store->find(User::class, $this->ids[User::class][0])->getProfile()?->getId(), 'the inverse side of a one-to-one');
    }

    public function testCollectionsAreReadOnFirstUseOrderedAndIndexed(): void
    {
        $alice = $this->store->find(User::class, $this->ids[User::class][0]);
        $queries = $this->store->queries();
        self::assertInstanceOf(LazyCollection::class, $alice->getThreads());
        self::assertSame($queries, $this->store->queries());
        self::assertSame(['Avignon', 'Zanzibar'], array_map(static fn (Thread $thread): string => $thread->getTitle(), $alice->getThreads()->toArray()));
        self::assertGreaterThan($queries, $this->store->queries());

        $article = $this->store->find(Thread::class, $this->ids[Article::class][0]);
        self::assertInstanceOf(Article::class, $article);
        self::assertEqualsCanonicalizing(['en', 'fr'], $article->getTranslations()->getKeys());
        self::assertSame('Zanzibar (fr)', $article->getTranslations()['fr']->getTitle());
        self::assertEqualsCanonicalizing(['news', 'travel'], array_map(static fn (Tag $tag): string => $tag->getName(), $article->getTags()->toArray()));
        $travel = $this->store->find(Tag::class, $this->ids[Tag::class][1]);
        self::assertEqualsCanonicalizing(['Zanzibar', 'Avignon'], array_map(static fn (Thread $thread): string => $thread->getTitle(), $travel->getThreads()->toArray()));
    }

    public function testASingleTableHierarchyReadsTheRowsClass(): void
    {
        [$cat] = $this->ids[Cat::class];
        self::assertInstanceOf(Cat::class, $this->store->find(Animal::class, $cat));
        self::assertNull($this->store->find(Dog::class, $cat), 'a dog reads no cat');
        self::assertSame(['Nemo', 'Rex', 'Tom'], array_map(static fn (Animal $animal): string => $animal->getName(), $this->store->select(Animal::class, static fn ($query) => $query->orderBy('t0.name'))));
        self::assertSame(['Rex'], array_map(static fn (Animal $animal): string => $animal->getName(), $this->store->select(Dog::class)));
    }

    public function testFindManyKeepsTheOrderGivenAndTheIdentityMap(): void
    {
        $ids = array_reverse($this->ids[Article::class]);
        $articles = $this->store->findMany(Thread::class, [...$ids, 999]);
        self::assertSame($ids, array_map(static fn (Thread $thread): ?int => $thread->getId(), $articles));
        self::assertSame($articles[0], $this->store->find(Article::class, $ids[0]));
    }

    public function testAMissingRowIsNullAndAGhostOfItThrows(): void
    {
        self::assertNull($this->store->find(User::class, 999));
        $unitOfWork = new EloquentUnitOfWork($this->store);
        $ghost = $unitOfWork->reference(User::class, 999);
        self::assertSame(999, $ghost->getId());
        $this->expectException(EntityNotFound::class);
        $ghost->getEmail();
    }

    public function testTheUnitOfWorkReadsAndRefusesToWrite(): void
    {
        $unitOfWork = new EloquentUnitOfWork($this->store);
        $user = $this->store->find(User::class, $this->ids[User::class][1]);
        self::assertTrue($unitOfWork->contains($user));
        self::assertFalse($unitOfWork->isNew($user));
        self::assertTrue($unitOfWork->isNew(new User('new@example.org')));
        self::assertSame($this->ids[User::class][1], $unitOfWork->identifier($user));
        self::assertSame(User::class, $unitOfWork->className($unitOfWork->reference(User::class, $this->ids[User::class][0])));
        $unitOfWork->refresh($user);
        self::assertSame('bob@example.org', $user->getEmail());
        foreach (['persist' => fn () => $unitOfWork->persist($user), 'remove' => fn () => $unitOfWork->remove($user), 'flush' => fn () => $unitOfWork->flush(),
            'transactional' => fn () => $unitOfWork->transactional(static fn () => null), 'changes' => fn () => $unitOfWork->changes($user), 'recompute' => fn () => $unitOfWork->recompute($user)] as $operation => $write) {
            try {
                $write();
                self::fail($operation.' wrote');
            } catch (UnsupportedPersistence $refused) {
                self::assertStringContainsString($operation.'()', $refused->getMessage());
            }
        }
    }

    public function testAConditionScopesEveryQuery(): void
    {
        $this->store->addCondition('not-bob', static function ($map, $query): void {
            if (User::class === $map->className()) {
                $query->where('t0.email', '<>', 'bob@example.org');
            }
        });
        self::assertNull($this->store->find(User::class, $this->ids[User::class][1]));
        self::assertNotNull($this->store->find(User::class, $this->ids[User::class][0]));
        $this->store->removeCondition('not-bob');
        self::assertNotNull($this->store->find(User::class, $this->ids[User::class][1]));
    }
}
