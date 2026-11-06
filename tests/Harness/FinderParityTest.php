<?php

namespace Omnibridge\Eloquent\Tests\Harness;

require_once \dirname(__DIR__).'/Site/autoload.php';

use Base\Database\Repository\Finder\Group;
use Base\Database\Repository\Finder\Key;
use Base\Database\Repository\UnsupportedQueryException;
use Base\Entity\Layout\ImageCrop;
use Base\Entity\Thread;
use Base\Entity\Thread\Comment;
use Base\Entity\Thread\Tag;
use Base\Entity\User\Notification;
use Base\Enum\CommentState;
use Base\Enum\ThreadState;
use Doctrine\ORM\EntityManagerInterface;
use Omnibridge\Eloquent\Omnibase\EloquentPersistence;
use Omnibridge\Eloquent\Tests\Support\FinderParity;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Tests\Base\Database\Repository\HandWrittenQueries;

/**
 * The finder language on both compilers, the same database (plan "omnibase agnostic", step F7b): omnibridge/doctrine's
 * ServiceEntityParser and omnibridge/eloquent's EloquentFinder, each call of omnisense/omnibase's FinderParityTest and
 * one of each word of the language, then the repositories' rewritten queries (the core's HandWrittenQueries' "after"
 * methods on Doctrine's repositories and on Eloquent's), on rows written for the purpose in a transaction rolled back.
 *
 * Run in omnisense/omnibase's harness (SQLite), the package mounted at /srv/omnibridge/eloquent with its own vendor/:
 *
 *     docker compose -f compose.yml -f compose.checkouts.yml -f <eloquent overlay> run --rm omnibase \
 *         test --filter FinderParityTest /srv/omnibridge/eloquent/tests/Harness/FinderParityTest.php
 *
 * The report - each scenario's outcome, both results - goes to var/eloquent-finder-parity-harness.json.
 */
final class FinderParityTest extends KernelTestCase
{
    // The differences known, each with its reason: Doctrine refuses what Eloquent compiles.
    private const FIELD_TEXTS = 'An order on a list of texts: omnibridge/doctrine writes FIELD(e.state,STATE_DRAFT,STATE_PUBLISH), the values unquoted, which DQL reads as names (a semantical error); EloquentFinder binds them.';
    private const RAND_SQLITE = 'RAND() on SQLite: omnibridge/doctrine\'s Rand function writes RAND(), which SQLite has not (RANDOM()); EloquentFinder writes the database\'s. On MySQL both write RAND().';
    private const EAGER_TAGS = 'Eagerly on Thread::$tags: Doctrine\'s fetch join of the second-level-cached tags leaves the threads\' collection unset (Base\\Entity\\Thread::getTags() returns null: a TypeError); the store reads associations lazily, Eagerly changes no row.';

    private EntityManagerInterface $entityManager;

    private EloquentPersistence $eloquent;

    protected function setUp(): void
    {
        if (!getenv('HARNESS_SUITE_BOOTSTRAP')) {
            self::markTestSkipped('Run in omnisense/omnibase\'s harness.');
        }
        self::bootKernel();
        $this->entityManager = static::getContainer()->get('doctrine')->getManager();
        $this->eloquent = FinderParity::persistence($this->entityManager, (string) static::getContainer()->getParameter('kernel.project_dir'));
    }

    public function testEachFinderCallGivesTheSameRowsOnBothCompilers(): void
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $ids = $this->write();
            $report = FinderParity::compare($this->finderScenarios($ids), $this->clear(...));
        } finally {
            $connection->rollBack();
            $this->entityManager->clear();
        }
        $this->record('finders', $report);
    }

    public function testTheRewrittenQueriesGiveTheSameRowsOnBothCompilers(): void
    {
        $entityManager = $this->entityManager;
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $this->writeRewritten();
            $latest = static function (string $class) use ($entityManager): ?object {
                $id = $entityManager->getClassMetadata($class)->getSingleIdentifierFieldName();

                return $entityManager->createQueryBuilder()->select('x')->from($class, 'x')->orderBy('x.'.$id, 'DESC')->setMaxResults(1)->getQuery()->getOneOrNullResult();
            };
            $trashed = $this->trashedThread();
            $doctrine = HandWrittenQueries::scenarios(fn (string $class) => $entityManager->getRepository($class), $latest, \App\Entity\User::class, null, $trashed);
            $eloquent = HandWrittenQueries::scenarios(fn (string $class) => $this->eloquent->repositories()->of($class), $latest, \App\Entity\User::class, null, $trashed);
            $scenarios = [];
            foreach ($doctrine as $name => [, $after]) {
                $scenarios[$name] = [$after, $eloquent[$name][1]];
            }
            // Step E2b: a trashed thread's comments on both, the book's.
            $scenarios['Comments::findVisible(the trashed thread)'] = [fn () => $entityManager->getRepository(Comment::class)->findVisible($trashed), fn () => $this->eloquent->repositories()->of(Comment::class)->findVisible($trashed)];
            $scenarios['Comments::countVisible(the trashed thread)'] = [fn () => $entityManager->getRepository(Comment::class)->countVisible($trashed), fn () => $this->eloquent->repositories()->of(Comment::class)->countVisible($trashed)];
            $scenarios['findByThreadNullAndParentNull (the join of before)'] = [fn () => $entityManager->getRepository(Comment::class)->findByThreadNullAndParentNull(['state' => CommentState::APPROVED], ['id' => 'ASC']), fn () => $this->eloquent->repositories()->of(Comment::class)->findByThreadNullAndParentNull(['state' => CommentState::APPROVED], ['id' => 'ASC'])];
            $report = FinderParity::compare($scenarios, $this->clear(...));
        } finally {
            $connection->rollBack();
            $entityManager->clear();
        }
        $this->record('rewritten', $report);
        self::assertGreaterThanOrEqual(40, \count($report));
    }

    /** @param array<string, array{outcome: string}> $report */
    private function record(string $part, array $report): void
    {
        $file = static::getContainer()->getParameter('kernel.project_dir').'/var/eloquent-finder-parity-harness.json';
        $all = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
        $all[$part] = ['counts' => FinderParity::counts($report), 'scenarios' => $report];
        file_put_contents($file, json_encode($all, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR)."\n");
        self::assertSame([], array_keys(FinderParity::unequal($report)), $part.': '.json_encode(FinderParity::unequal($report), \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    private function clear(): void
    {
        $this->entityManager->clear();
        $this->eloquent->store()->clear();
    }

    /** @return array<string, int> */
    private function write(): array
    {
        $em = $this->entityManager;
        $red = new Tag('Red', 'red');
        $blue = new Tag('Blue', 'blue');
        $em->persist($red);
        $em->persist($blue);

        $make = static function (string $title, string $slug, string $state, ?string $published, ?Thread $parent = null, array $tags = []) use ($em): Thread {
            $thread = new Thread(null, $parent, $title, $slug.'-'.bin2hex(random_bytes(2)));
            $thread->setState($state);
            null === $published || $thread->setPublishedAt(new \DateTime($published));
            foreach ($tags as $tag) {
                $thread->addTag($tag);
            }
            $em->persist($thread);

            return $thread;
        };
        $alpha = $make('Alpha', 'alpha', ThreadState::PUBLISH, '-2 days', null, [$red]);
        $beta = $make('Beta', 'beta_one', ThreadState::DRAFT, '-40 days', $alpha, [$red, $blue]);
        $gamma = $make('Gamma', 'Gamma-two', ThreadState::PUBLISH, '+3 days');
        $delta = $make('Delta', 'delta', ThreadState::PUBLISH, null, $alpha);
        $epsilon = $make('Epsilon', 'epsilon', ThreadState::PUBLISH, '-3 hours', $beta, [$blue]);
        $zeta = $make('Zeta', 'zeta', ThreadState::PUBLISH, '-400 days', $alpha);
        $epsilon->setDeletedAt(new \DateTime('-1 hour'));
        $em->flush();

        $ids = ['alpha' => $alpha->getId(), 'beta' => $beta->getId(), 'gamma' => $gamma->getId(), 'delta' => $delta->getId(), 'epsilon' => $epsilon->getId(), 'zeta' => $zeta->getId(), 'red' => $red->getId(), 'blue' => $blue->getId()];
        $ids['slug'] = $alpha->getSlug();
        $ids['betaSlug'] = $beta->getSlug();
        $em->clear();

        return $ids;
    }

    /**
     * The calls: omnisense/omnibase's FinderParityTest's, on the rows written (the ids given where they name one), then
     * the words its scenarios leave out.
     *
     * @param array<string, mixed> $ids
     *
     * @return array<string, array{0: \Closure(): mixed, 1: \Closure(): mixed, 2?: bool}>
     */
    private function finderScenarios(array $ids): array
    {
        $at = new \DateTimeImmutable('2026-05-01T12:00:00+00:00');
        $calls = [
            // FinderParityTest's scenarios (omnisense/omnibase tests/Database/Repository), on the rows written.
            [Thread::class, 'findBySlug', [$ids['slug']]],
            [Thread::class, 'findOneBySlug', [$ids['slug']]],
            [Thread::class, 'cacheOneBySlug', [$ids['slug']]],
            [Thread::class, 'findByState', [ThreadState::PUBLISH], true],
            [Thread::class, 'findByStateAndSlug', [ThreadState::PUBLISH, $ids['slug']]],
            [Thread::class, 'findBySlugOrState', [$ids['slug'], ThreadState::DRAFT], true],
            [Thread::class, 'findBy', [['state' => ThreadState::PUBLISH], ['id' => 'DESC'], 5, 1]],
            [Thread::class, 'findAll', [], true],
            [Thread::class, 'cacheAll', [['id' => 'DESC']]],
            [Thread::class, 'findByStateAndTags', [[ThreadState::PUBLISH, ThreadState::DRAFT], [$ids['red'], $ids['blue']]], true],
            [Thread::class, 'cacheBy', [['state' => ThreadState::PUBLISH]], true],
            [Thread::class, 'findBySlugNotEqualTo', [$ids['slug']], true],
            [Thread::class, 'findByIdGreaterThan', [$ids['beta']], true],
            [Thread::class, 'findByIdLowerEqualTo', [$ids['gamma']], true],
            [Thread::class, 'findByPublishedAtOlderThan', ['-1 year'], true],
            [Thread::class, 'findByPublishedAtYoungerEqualTo', ['-1 day'], true],
            [Thread::class, 'findByPublishedAtIsOver', [], true],
            [Thread::class, 'findByPublishedAtIsNotOver', [], true],
            [Thread::class, 'findByPublishedAtGreaterThan', [$at], true],
            [Thread::class, 'findByParentNull', [], true],
            [Thread::class, 'findByParentNotNull', [], true],
            [Thread::class, 'findBySlugEmpty', [], true],
            [Thread::class, 'findBySlugStartingWith', ['al'], true],
            [Thread::class, 'findBySlugEndingWith', [substr($ids['slug'], -4)], true],
            [Thread::class, 'findBySlugNotStartingWith', ['al'], true],
            [Thread::class, 'findByInsensitiveSlug', [strtoupper($ids['slug'])], true],
            [Thread::class, 'findByPartialSlug', ['%et%'], true],
            [Thread::class, 'findByStateAndBut', [ThreadState::PUBLISH, $ids['alpha']], true],
            [Thread::class, 'findByStateAndInstanceOf', [ThreadState::PUBLISH, Thread::class], true],
            [Thread::class, 'findByStateAndClassOf', [ThreadState::PUBLISH, Thread::class], true],
            [Thread::class, 'cacheByInstanceOf', [[Thread::class], [], [], null, null, ['id']], true],
            [Thread::class, 'findAtMost3ByState', [ThreadState::PUBLISH, [], ['id' => 'ASC']]],
            [Thread::class, 'findLast2ByState', [ThreadState::PUBLISH]],
            [Thread::class, 'findLastOneByState', [ThreadState::PUBLISH]],
            [Thread::class, 'findPreviousByState', [['id' => $ids['zeta']], ThreadState::PUBLISH]],
            [Thread::class, 'findNext2ByState', [['id' => $ids['alpha']], ThreadState::PUBLISH]],
            [Thread::class, 'cachePreviousOneByClassOfAndState', [['id' => $ids['zeta']], Thread::class, ThreadState::PUBLISH]],
            [Thread::class, 'cacheNextOneByClassOfAndState', [['publishedAt' => new \DateTime('-30 days')], Thread::class, ThreadState::PUBLISH]],
            [Thread::class, 'cacheOneBySlugWithRouteAndState', [['slug' => $ids['slug']], 'slug', [ThreadState::PUBLISH], [], ['publishedAt' => 'DESC']]],
            [Thread::class, 'cacheByParentAndStateAndPublishedAtWithin', [$ids['alpha'], [ThreadState::PUBLISH], '1 week', [], ['publishedAt' => 'DESC']]],
            [Thread::class, 'cacheOneByStateAndSlugStartingWith', [[ThreadState::PUBLISH], 'al']],
            [Thread::class, 'countByState', [ThreadState::PUBLISH]],
            [Thread::class, 'countByStateAndPublishedAtYoungerThan', [ThreadState::PUBLISH, '-1 month']],
            [Thread::class, 'distinctCountByState', [ThreadState::PUBLISH]],
            [Thread::class, 'countForParentByState', [ThreadState::PUBLISH]],
            [Thread::class, 'lengthOfTagsByState', [ThreadState::PUBLISH], true],
            [\App\Entity\User::class, 'findByIdNotEqualToAndActiveAtYoungerThan', [5, 300], true],
            [\App\Entity\User::class, 'findByActiveAtYoungerThan', [300], true],
            [\App\Entity\User::class, 'cacheByRoles', [['ROLE_ADMIN']], true],
            [\App\Entity\User::class, 'findOneByEmail', ['someone@example.org']],
            [\App\Entity\User::class, 'findByIdOrEmail', [5, 'someone@example.org'], true],
            [Notification::class, 'findByIsReadAndSentAtOlderThan', [true, '-1 month'], true],
            [Notification::class, 'findByUserAndIsRead', [5, false], true],
            [ImageCrop::class, 'cacheOneByRatio0ClosestTo', [1.5, ['image.source' => 'a.jpg'], [], [], ['ratio0' => 'e.width0/e.height0']]],
            [ImageCrop::class, 'cacheOneByRatio0ClosestTo', [1.5, ['image.source' => 'a.jpg'], [], null, [], ['ratio0' => 'e.width0/e.height0']]],
            [ImageCrop::class, 'findOneByRatio0ClosestToAndWidth0ClosestToAndHeight0ClosestTo', [1.5, 300, 200, ['image.source' => 'a.jpg'], [], null, [], ['ratio0' => 'e.width0/e.height0']]],

            // The words FinderParityTest leaves out.
            [Thread::class, 'findByStateNotEqualTo', [ThreadState::PUBLISH], true],
            [Thread::class, 'findByStateAndNotInstanceOf', [ThreadState::PUBLISH, Thread::class], true],
            [Thread::class, 'findByStateAndNotClassOf', [ThreadState::PUBLISH, Thread::class], true],
            [Thread::class, 'findByStateAndInstanceOf', [ThreadState::PUBLISH, '^'.Thread::class], true],
            [Thread::class, 'findByStateAndClassOf', [ThreadState::PUBLISH, ['^'.Thread::class]], true],
            [Thread::class, 'findByStateAndMemberOf', [ThreadState::PUBLISH, Thread::class], true],
            [Thread::class, 'findByStateAndInstanceOf', [ThreadState::PUBLISH, 'Nowhere\\Class'], true],
            [Thread::class, 'findBySlugEndingWithAndStateNotEqualTo', ['a', ThreadState::DRAFT], true],
            [Thread::class, 'findBySlugNotEndingWith', ['a'], true],
            [Thread::class, 'findByInsensitiveSlugStartingWith', ['GAMMA'], true],
            [Thread::class, 'findBySlugNotEmpty', [], true],
            [Thread::class, 'findByPublishedAtNull', [], true],
            [Thread::class, 'findByPublishedAtNotNull', [], true],
            [Thread::class, 'findByPublishedAtOlderEqualTo', [86400], true],
            [Thread::class, 'findByPublishedAtYoungerThan', [7 * 86400], true],
            [Thread::class, 'findByPublishedAtWithin', ['+1 week'], true],
            [Thread::class, 'findByPublishedAtLowerThan', [new \DateTimeImmutable('-1 day')], true],
            [Thread::class, 'findByPublishedAtGreaterEqualTo', ['2020-01-01 00:00:00'], true],
            [Thread::class, 'findByPublishedAtYoungerThan', [new \DateTime('-1 day')], true],
            [Thread::class, 'findByIdLowerThanOrIdGreaterThan', [$ids['beta'], $ids['epsilon']], true],
            [Thread::class, 'findByStateAndAndOr', [ThreadState::PUBLISH], true],
            [Thread::class, 'findByParent', [$ids['alpha']], true],
            [Thread::class, 'findByParent', [[$ids['alpha'], $ids['beta']]], true],
            [Thread::class, 'findByParent', [[]], true],
            [Thread::class, 'findByParent', [null], true],
            [Thread::class, 'findByParentNotEqualTo', [$ids['alpha']], true],
            [Thread::class, 'findByTags', [[$ids['red']]], true],
            [Thread::class, 'findByTags', [$ids['blue']], true],
            [Thread::class, 'findBy', [['parent' => Key::of($ids['alpha'])], ['id' => 'ASC']]],
            [Thread::class, 'findBy', [['parent' => Key::of([$ids['alpha'], $ids['beta']])], ['id' => 'ASC']]],
            [Thread::class, 'findBy', [['parent' => Key::of([])], ['id' => 'ASC']]],
            [Thread::class, 'findByParentNotEqualTo', [Key::of(null), [], ['id' => 'ASC']]],
            [Thread::class, 'findBy', [[Group::by('SlugStartingWithOrSlugStartingWith', 'al', 'de'), 'state' => ThreadState::PUBLISH], ['id' => 'ASC']]],
            [Thread::class, 'findByStateOrState', [ThreadState::DRAFT, ThreadState::PUBLISH, [Group::by('ParentNullAndPublishedAtNotNull')], ['id' => 'ASC']]],
            [Thread::class, 'count', [[Group::by('ParentNotNullOrStateEqualTo', ThreadState::DRAFT)]]],
            [Thread::class, 'findBy', [['parent.slug' => $ids['slug']], ['id' => 'ASC']]],
            [Thread::class, 'findBy', [['tags.slug' => 'red'], ['id' => 'ASC']]],
            [Thread::class, 'findBy', [['state' => ThreadState::PUBLISH], ['parent.slug' => 'ASC', 'id' => 'DESC']]],
            [Thread::class, 'findBy', [['state' => ThreadState::PUBLISH], ['title' => 'ASC']]],
            [Thread::class, 'findBy', [[], ['slug' => 'DESC'], 2, 2]],
            [Thread::class, 'findBy', [['state' => [ThreadState::PUBLISH, ThreadState::DRAFT]], ['state' => [ThreadState::DRAFT, ThreadState::PUBLISH], 'id' => 'ASC']], false, self::FIELD_TEXTS],
            [Thread::class, 'findBy', [[], ['id' => [$ids['gamma'], $ids['alpha'], $ids['beta']]]]],
            [Thread::class, 'findBy', [[], ['publishedAt' => 'ASC', 'id' => 'ASC'], null, 2]],
            [Thread::class, 'findRandomlyByState', [ThreadState::PUBLISH], true, self::RAND_SQLITE],
            [Thread::class, 'findAtMost2ByState', [ThreadState::PUBLISH, [], ['id' => 'DESC']]],
            [Thread::class, 'findNextOneByState', [['publishedAt' => new \DateTimeImmutable('-30 days')], ThreadState::PUBLISH]],
            [Thread::class, 'findPreviousOneByState', [['publishedAt' => new \DateTime('now')], ThreadState::PUBLISH]],
            [Thread::class, 'findLastOneByState', [ThreadState::PUBLISH, [], ['publishedAt' => 'DESC']]],
            [Thread::class, 'findOneBySlugWithRoute', [['slug' => $ids['betaSlug']], 'slug']],
            [Thread::class, 'findOneBySlugWithRoute', [['other' => 'x'], 'slug']],
            [Thread::class, 'findEagerlyByState', [['tags'], ThreadState::PUBLISH], true, self::EAGER_TAGS],
            [Thread::class, 'countForTagsByState', [ThreadState::PUBLISH]],
            [Thread::class, 'distinctCountForTagsByState', [ThreadState::PUBLISH]],
            [Thread::class, 'countByState', [ThreadState::PUBLISH, [], '', null, ['parent']], true],
            [Thread::class, 'countByState', [ThreadState::PUBLISH, [], '', null, ['state']], true],
            [Thread::class, 'lengthOfSlugByState', [ThreadState::PUBLISH], true],
            [Thread::class, 'lengthOfSlugBy', [[], ['id' => 'ASC'], null, 2]],
            [Thread::class, 'maxOfPublishedAt', []],
            [Thread::class, 'minOfIdByState', [ThreadState::PUBLISH]],
            [Thread::class, 'sumOfIdByStateNotEqualTo', [ThreadState::PUBLISH]],
            [Thread::class, 'sumOfIdByState', ['nothing']],
            [Thread::class, 'findByModel', [(new Thread())->setState(ThreadState::DRAFT)], true],
            [Thread::class, 'findByExample', [(new Thread())->setState(ThreadState::DRAFT)]],
            [Thread::class, 'findByInsensitiveIdentifier', [strtoupper(substr($ids['slug'], 0, 3))], true],
            [Thread::class, 'findByStateAndTitle', [ThreadState::PUBLISH, 'Alpha'], true],
            [Thread::class, 'findByStateIsOver', [ThreadState::PUBLISH], true],
            [Thread::class, 'findByUnknownField', ['x']],
            [Thread::class, 'findBySlugAndStateOrId', ['a', 'b', 1]],
            [Tag::class, 'findBySlug', [['red', 'blue']], true],
            [Tag::class, 'countForThreadsBy', [[]]],
            [Tag::class, 'lengthOfThreadsBy', [[], ['slug' => 'ASC']]],
        ];

        $scenarios = [];
        foreach ($calls as $index => $call) {
            [$class, $method, $arguments] = $call;
            $name = sprintf('%02d %s::%s(%s)', $index, substr($class, strrpos($class, '\\') + 1), $method, mb_substr((string) json_encode(FinderParity::normalise($arguments), \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR), 0, 120));
            $scenarios[$name] = [
                fn () => $this->entityManager->getRepository($class)->{$method}(...$arguments),
                fn () => $this->eloquent->repositories()->of($class)->{$method}(...$arguments),
                $call[3] ?? false,
                $call[4] ?? null,
            ];
        }
        $scenarios['findRandomlyByState (how many)'] = [
            fn () => \count($this->entityManager->getRepository(Thread::class)->findRandomlyByState(ThreadState::PUBLISH)->getResult()),
            fn () => \count($this->eloquent->repositories()->of(Thread::class)->findRandomlyByState(ThreadState::PUBLISH)->getResult()),
            false,
            self::RAND_SQLITE,
        ];

        return $scenarios;
    }

    /** The rows omnisense/omnibase's FinderParityTest writes for the rewritten queries (step E2, E2b). */
    private function writeRewritten(): void
    {
        $entityManager = $this->entityManager;
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $entityManager->persist(new \Base\Entity\Layout\Redirection('/old-page', '/new-page'));
        $entityManager->persist((new \Base\Entity\Layout\Redirection('/Mixed/Case', '/target'))->setEnabled(true));
        $entityManager->persist(new \Base\Entity\Layout\Redirection('/legacy/*', '/archive/*'));
        $entityManager->persist((new \Base\Entity\Layout\Redirection('/off', '/on'))->setEnabled(false));
        foreach ([['messages', 'fr', 'a.key', 'Un'], ['messages', 'en', 'a.key', 'One'], ['forms', 'fr', 'b_key', 'Deux']] as [$domain, $locale, $key, $value]) {
            $entityManager->persist((new \Base\Entity\Layout\TextOverride())->setDomain($domain)->setLocale($locale)->setKey($key)->setValue($value));
        }
        $entityManager->persist(new \Base\Entity\Hours\SpecialDay($now->modify('+3 days'), $now->modify('+5 days'), 'Holidays'));
        $entityManager->persist(new \Base\Entity\Hours\SpecialDay($now->modify('+1 day'), null, 'Inventory', 'office'));
        $entityManager->persist(new \Base\Entity\Hours\SpecialDay($now->modify('-90 days'), null, 'Long ago'));
        $entityManager->persist(new \Base\Entity\Hours\ScopedWeek('office', [1 => [['09:00', '17:00']]]));
        foreach ([[Thread::class, 7, '-2 days'], [Thread::class, 8, null], [\Base\Entity\User::class, 7, '+2 days'], [Thread::class, 7, '-1 day']] as [$class, $id, $permanent]) {
            $trashBall = (new \Base\Entity\Extension\TrashBall())->setEntityClass($class);
            $trashBall->setEntityId($id);
            $trashBall->setAction(\Base\Enum\EntityAction::DELETE);
            $trashBall->setCreatedAt(new \DateTime());
            null === $permanent || $trashBall->setPermanentAfter(new \DateTime($permanent));
            $entityManager->persist($trashBall);
        }
        $entityManager->flush();

        $live = new Thread(null, null, 'A thread commented', 'a-thread-commented-'.bin2hex(random_bytes(3)));
        $trashed = new Thread(null, null, 'A thread in the trash', 'a-thread-in-the-trash-'.bin2hex(random_bytes(3)));
        $trashed->setDeletedAt(new \DateTime('-1 hour'));
        $book = (new Comment())->setName('Anne')->setContent('In the book');
        $written = [
            [$book, CommentState::APPROVED],
            [(new Comment())->setName('Bob')->setContent('A reply in the book')->setParent($book), CommentState::APPROVED],
            [(new Comment($live))->setName('Carl')->setContent('Waiting'), CommentState::PENDING],
            [(new Comment($live))->setName('Dora')->setContent('On the live thread'), CommentState::APPROVED],
            [(new Comment($trashed))->setName('Eve')->setContent('Under the trashed thread'), CommentState::APPROVED],
        ];
        $entityManager->persist($live);
        $entityManager->persist($trashed);
        foreach ($written as [$comment]) {
            $entityManager->persist($comment);
        }
        $entityManager->flush();
        foreach ($written as $i => [$comment, $state]) {
            $entityManager->createQuery('UPDATE '.Comment::class.' c SET c.createdAt = :at, c.state = :state WHERE c.id = :id')
                ->setParameter('at', $now->modify('-'.(10 - $i).' minutes'))->setParameter('state', $state)->setParameter('id', $comment->getId())
                ->execute();
        }
        $this->trashedId = $trashed->getId();

        $views = $entityManager->getRepository(\Base\Entity\Analytics\PageView::class);
        $visits = $entityManager->getRepository(\Base\Entity\Analytics\Visit::class);
        foreach ([['/', 0], ['/', 0], ['/', 40 * 86400], ['/about', 3600]] as [$path, $ago]) {
            $views->incrementView($path, $now->modify('-'.$ago.' seconds'));
            $visits->recordPresence(\Base\Entity\Analytics\Visit::TYPE_VISITOR, md5($path.$ago), $now->modify('-'.$ago.' seconds'));
        }
        $views->incrementView('/', $now, \Base\Entity\Analytics\PageView::SOURCE_BOT);
        $visits->recordPresence(\Base\Entity\Analytics\Visit::TYPE_USER, '1', $now);
        $entityManager->clear();
    }

    private ?int $trashedId = null;

    /** The trashed thread read with the trash in view (Doctrine loads a reference to Thread, an inheritance's root). */
    private function trashedThread(): Thread
    {
        $filters = $this->entityManager->getFilters();
        $filters->disable('trash_filter');
        try {
            return $this->entityManager->find(Thread::class, $this->trashedId);
        } finally {
            $filters->enable('trash_filter');
        }
    }
}
