<?php

namespace Omnibridge\Eloquent\Tests\Omnibase;

use Base\Database\Exception\TableNotFound;
use Base\Database\Exception\UniqueConstraintViolation;
use Base\Database\Repository\Finder\Result;
use Base\Database\Repository\ServiceEntityRepository;
use Illuminate\Database\UniqueConstraintViolationException;
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
use Omnibridge\Schema\Builder;
use Omnibridge\Schema\Database\SchemaCreator;
use PHPUnit\Framework\TestCase;

/**
 * The database's refusals as the core names them, whatever the persistence: a flush refused by a unique constraint
 * throws Base\Database\Exception\UniqueConstraintViolation, a finder on a table the database does not have
 * Base\Database\Exception\TableNotFound - each with Illuminate's exception as the previous one.
 */
final class DatabaseErrorsTest extends TestCase
{
    private \PDO $pdo;

    private EntityStore $store;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $description = (new Builder())->build([Thread::class, Article::class, Page::class, Tag::class, Translation::class, User::class, Profile::class]);
        (new SchemaCreator($this->pdo))->create($description);
        $this->store = new EntityStore(Connections::pdo($this->pdo), new Mapping($description), Casts::utc());
    }

    public function testAFlushRefusedByAUniqueConstraintThrowsTheCoresException(): void
    {
        $unitOfWork = new EloquentUnitOfWork($this->store);
        $unitOfWork->persist(new User('same@example.org'));
        $unitOfWork->flush();
        $unitOfWork->persist(new User('same@example.org'));
        try {
            $unitOfWork->flush();
            self::fail('The second address was written.');
        } catch (UniqueConstraintViolation $violation) {
            self::assertInstanceOf(UniqueConstraintViolationException::class, $violation->getPrevious());
        }
        self::assertFalse($unitOfWork->isOpen(), 'a failed flush closes the unit of work, as Doctrine\'s');
    }

    public function testAFinderOnAMissingTableThrowsTheCoresException(): void
    {
        $this->pdo->exec('DROP TABLE '.$this->store->mapping()->of(Tag::class)->tables[0]);
        $tags = new ServiceEntityRepository(new EloquentFinders($this->store), Tag::class);
        $reads = [
            'findBy()' => static fn () => $tags->findBy([]),
            'count()' => static fn () => $tags->count(),
            'find()' => static fn () => $tags->find(1),
            'a finder\'s result' => static function () use ($tags): mixed {
                $result = $tags->findByName('red');
                \assert($result instanceof Result);

                return $result->getResult();
            },
            'a finder\'s count' => static function () use ($tags): mixed {
                $result = $tags->findByName('red');
                \assert($result instanceof Result);

                return $result->count();
            },
        ];
        foreach ($reads as $read => $call) {
            try {
                $call();
                self::fail($read.' read a table that does not exist.');
            } catch (TableNotFound $missing) {
                self::assertInstanceOf(\Illuminate\Database\QueryException::class, $missing->getPrevious(), $read);
            }
        }
    }
}
