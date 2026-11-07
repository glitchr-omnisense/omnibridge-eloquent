<?php

namespace Omnibridge\Eloquent\Tests\Site;

require_once __DIR__.'/autoload.php';

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Omnibridge\Eloquent\Omnibase\EloquentPersistence;
use Omnibridge\Eloquent\Tests\Support\FinderParity;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The finder language on both compilers, on lesringostars' data (plan "omnibase agnostic", step F7b): the site's
 * FinderParityTest calls - its own and the core's on the site's path, one of each word (61) - and the repositories'
 * rewritten queries of step E2 (HandWrittenQueries' 48, the site's 3, Comments' 7 on a thread put in the trash),
 * each on omnibridge/doctrine and on omnibridge/eloquent, the same MySQL database and transaction, compared.
 *
 * A method of the site's own repositories (App\Repository\*: oneByEmail(), oneByStateAndTagsAndIdNot()...) is
 * called on Doctrine as the site's test calls it, and on Eloquent as the finder it is written on (its body): the
 * site's repositories on Eloquent are step F7f.
 *
 * Run in the site's Symfony test kernel, the package copied into its vendor (autoload.php), everything in a
 * transaction rolled back (the comments of the last test are written in it, then gone):
 *
 *     OMNIBRIDGE_ELOQUENT_SITE=1 php bin/phpunit vendor/omnibridge/eloquent/tests/Site/FinderParityTest.php
 *
 * The report - each scenario's outcome, both results - goes to var/eloquent-finder-parity-site.json.
 */
final class FinderParityTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private EloquentPersistence $eloquent;

    protected function setUp(): void
    {
        if (!getenv('OMNIBRIDGE_ELOQUENT_SITE')) {
            self::markTestSkipped('Run in an application with OMNIBRIDGE_ELOQUENT_SITE=1.');
        }
        self::bootKernel();
        $this->entityManager = static::getContainer()->get('doctrine')->getManager();
        $this->eloquent = FinderParity::persistence($this->entityManager, (string) static::getContainer()->getParameter('kernel.project_dir'));
    }

    public function testTheSitesFinderCallsGiveTheSameRowsOnBothCompilers(): void
    {
        $report = $this->inTransaction(fn () => FinderParity::compare($this->siteScenarios(), $this->clear(...)));
        self::assertCount(61, $report);
        $this->record('finders', $report);
    }

    public function testTheRewrittenQueriesGiveTheSameRowsOnBothCompilers(): void
    {
        $report = $this->inTransaction(fn () => FinderParity::compare($this->rewrittenScenarios(), $this->clear(...)));
        self::assertCount(51, $report);
        $this->record('rewritten', $report);
    }

    /**
     * Comments::findVisible() and countVisible() on the thread compared by its key (step E2b), on comments written
     * under two of the site's threads - the newest, and the one before it put in the trash - and in the visitors' book,
     * through the connection, in the transaction rolled back after (as the site's FinderParityTest writes them).
     */
    public function testTheCommentsOfAThreadInTheTrashOnBothCompilers(): void
    {
        $report = $this->inTransaction(function (): array {
            $connection = $this->entityManager->getConnection();
            $comments = $this->entityManager->getClassMetadata(\Base\Entity\Thread\Comment::class);
            $threads = $this->entityManager->getClassMetadata(\Base\Entity\Thread::class);
            [$liveId, $trashedId] = array_map('intval', $connection->fetchFirstColumn('SELECT id FROM '.$threads->getTableName().' WHERE '.$threads->getColumnName('deletedAt').' IS NULL ORDER BY id DESC LIMIT 2'));
            $connection->update($threads->getTableName(), [$threads->getColumnName('deletedAt') => (new \DateTime('-1 hour'))->format('Y-m-d H:i:s')], ['id' => $trashedId]);
            $write = static function (?int $thread, ?int $parent, string $state, int $minutesAgo) use ($connection, $comments): int {
                $connection->insert($comments->getTableName(), [
                    'name' => 'F7b', 'content' => 'A comment of the parity test', 'state' => $state, 'class' => $comments->discriminatorValue ?? 'comment',
                    'createdAt' => (new \DateTime('-'.$minutesAgo.' minutes'))->format('Y-m-d H:i:s'), 'thread_id' => $thread, 'parent_id' => $parent,
                ]);

                return (int) $connection->lastInsertId();
            };
            $book = $write(null, null, 'approved', 10);
            $write(null, $book, 'approved', 9);
            $write($liveId, null, 'pending', 8);
            $write($liveId, null, 'approved', 7);
            $write($trashedId, null, 'approved', 6);
            $this->entityManager->clear();
            $this->clear();

            $filters = $this->entityManager->getFilters();
            $filters->disable('trash_filter');
            try {
                $trashed = $this->entityManager->find(\Base\Entity\Thread::class, $trashedId);
            } finally {
                $filters->enable('trash_filter');
            }
            $scenarios = [];
            foreach ($this->handWritten($trashed) as $name => $scenario) {
                if (str_starts_with($name, 'Comments::') && 'Comments::countPending' !== $name) {
                    $scenarios[$name] = $scenario;
                }
            }
            $scenarios['Comments::findVisible(the trashed thread)'] = [fn () => $this->doctrine(\Base\Entity\Thread\Comment::class)->findVisible($trashed), fn () => $this->eloquent(\Base\Entity\Thread\Comment::class)->findVisible($trashed)];
            $scenarios['Comments::findVisible(the visitors\' book, 1)'] = [fn () => $this->doctrine(\Base\Entity\Thread\Comment::class)->findVisible(null, 1), fn () => $this->eloquent(\Base\Entity\Thread\Comment::class)->findVisible(null, 1)];

            return FinderParity::compare($scenarios, $this->clear(...));
        });
        self::assertCount(9, $report);
        $this->record('comments of a trashed thread', $report);
    }

    /**
     * One of each word of the language and of what the compiler adds - the paths through an association (a
     * translation's: the Translatable join), the key, the groups, the aggregates, the grouped counts and lengths,
     * FIELD() and RAND() - on the site's rows (read only), so that MySQL compiles each on both.
     */
    public function testEachWordOnTheSitesDataGivesTheSameRowsOnBothCompilers(): void
    {
        $report = $this->inTransaction(fn () => FinderParity::compare($this->wordScenarios(), $this->clear(...)));
        $this->record('words', $report);
        self::assertGreaterThanOrEqual(60, \count($report));
    }

    /**
     * Groups built from their members (Group::of(), step B1b): what the back office's list asks.
     *
     * @return list<array{0: class-string, 1: string, 2: list<mixed>}>
     */
    private static function builtGroups(string $A, string $U, object $article, object $older): array
    {
        $G = \Base\Database\Repository\Finder\Group::class;
        $C = \Base\Database\Repository\Finder\Criterion::class;
        $P = \Base\Database\Repository\Finder\Parser::class;
        $like = static fn (string $field, string $words) => $C::of($field, '%'.mb_strtolower($words).'%', $P::OPTION_INSENSITIVE, $P::OPTION_PARTIAL);

        return [
            [$A, 'findBy', [[$G::of($P::SEPARATOR_OR, $like('slug', 'A'), $like('slug', 'tour'))], ['id' => 'DESC'], 20, 20]],
            [$A, 'count', [[$G::of($P::SEPARATOR_OR, $like('slug', 'a'), $like('slug', 'tour'))]]],
            [$A, 'findBy', [[$G::of($P::SEPARATOR_AND, $C::of('state', \Base\Enum\ThreadState::PUBLISH)), $G::of($P::SEPARATOR_AND, $C::of('publishedAt', new \DateTimeImmutable('2024-01-01 00:00:00'), $P::OPTION_GREATER_EQUAL), $C::of('publishedAt', new \DateTimeImmutable('2025-12-31 23:59:59'), $P::OPTION_LOWER_EQUAL))], ['publishedAt' => 'DESC', 'id' => 'DESC'], 20]],
            [$A, 'findBy', [[$G::of($P::SEPARATOR_AND, $C::of('parent', \Base\Database\Repository\Finder\Key::of((string) ($article->getParent()?->getId() ?? 0))))], ['id' => 'DESC']]],
            [$A, 'findOneBy', [[$G::of($P::SEPARATOR_AND, $C::of('id', $older->getId(), $P::OPTION_GREATER))], ['id' => 'ASC']]],
            [$A, 'findOneBy', [[$G::of($P::SEPARATOR_AND, $C::of('id', $article->getId(), $P::OPTION_LOWER))], ['id' => 'DESC']]],
            [$U, 'findBy', [[$G::of($P::SEPARATOR_AND, $C::of('roles', '%ROLE_ADMIN%', $P::OPTION_PARTIAL))], ['id' => 'ASC']]],
            [$U, 'findBy', [[$G::of($P::SEPARATOR_OR, $like('username', 'M'), $like('email', 'GMAIL'))], ['username' => 'ASC', 'id' => 'ASC'], 20]],
            [$U, 'count', [[$G::of($P::SEPARATOR_AND, $C::of('id', 3, $P::OPTION_GREATER), $C::of('id', 9, $P::OPTION_NOT_EQUAL))]]],
        ];
    }

    /** @return array<string, array{0: \Closure(): mixed, 1: \Closure(): mixed, 2?: bool, 3?: string|null}> */
    private function wordScenarios(): array
    {
        $article = $this->latest(\App\Entity\Article\Article::class, ['state' => \Base\Enum\ThreadState::PUBLISH]);
        $older = $this->doctrine(\App\Entity\Article\Article::class)->findBy(['state' => \Base\Enum\ThreadState::PUBLISH], ['id' => 'ASC'], 1)[0] ?? $article;
        $published = [\Base\Enum\ThreadState::PUBLISH];
        $slug = (string) $article->getSlug();
        $title = (string) $article->getTitle();
        $parent = $article->getParent()?->getId();
        $tags = array_map(static fn (object $tag) => $tag->getId(), $article->getTags()->toArray());
        $A = \App\Entity\Article\Article::class;
        $T = \Base\Entity\Thread::class;
        $U = \App\Entity\User::class;

        $calls = [
            [$A, 'findByStateNotEqualTo', [\Base\Enum\ThreadState::PUBLISH, [], ['id' => 'ASC']]],
            [$T, 'findByStateAndNotInstanceOf', [$published, $A, [], ['id' => 'ASC']]],
            [$T, 'findByStateAndNotClassOf', [$published, \App\Entity\Gallery\Gallery::class, [], ['id' => 'ASC']]],
            [$T, 'findByStateAndInstanceOf', [$published, [$A, \App\Entity\Gallery\Gallery::class], [], ['id' => 'ASC']]],
            [$T, 'findByStateAndInstanceOf', [$published, '^'.$A, [], ['id' => 'ASC']]],
            [$T, 'findByStateAndClassOf', [$published, ['^'.\App\Entity\Gallery\Gallery::class], [], ['id' => 'ASC']]],
            [$T, 'countByClassOf', [\App\Entity\Article\Comment::class]],
            [$T, 'countByInstanceOf', [\App\Entity\Article\Comment::class]],
            [$T, 'findByStateAndMemberOf', [$published, $A]],
            [$A, 'findBySlugEndingWithAndStateNotEqualTo', [substr($slug, -1), \Base\Enum\ThreadState::DRAFT, [], ['id' => 'ASC']]],
            [$A, 'findBySlugNotEndingWith', [substr($slug, -1), [], ['id' => 'ASC']]],
            [$A, 'findByInsensitiveSlugStartingWith', [strtoupper(substr($slug, 0, 3)), [], ['id' => 'ASC']]],
            [$A, 'findBySlugNotEmpty', [[], ['id' => 'ASC']]],
            [$A, 'findBySlugEmpty', [[], ['id' => 'ASC']]],
            [$A, 'findByPublishedAtNull', [[], ['id' => 'ASC']]],
            [$A, 'findByPublishedAtNotNull', [[], ['id' => 'ASC'], 20]],
            [$A, 'findByPublishedAtOlderEqualTo', [30 * 86400, [], ['id' => 'ASC']]],
            [$A, 'findByPublishedAtYoungerThan', ['+400 days', [], ['id' => 'ASC']]],
            [$A, 'findByPublishedAtWithin', ['+1 week', [], ['id' => 'DESC'], 10]],
            [$A, 'findByPublishedAtIsNotOver', [[], ['id' => 'ASC']]],
            [$A, 'findByPublishedAtLowerThan', [$article->getPublishedAt(), [], ['id' => 'DESC'], 5]],
            [$A, 'findByPublishedAtGreaterEqualTo', ['2024-01-01 00:00:00', [], ['id' => 'ASC']]],
            [$A, 'findByIdLowerThanOrIdGreaterThan', [$older->getId() + 3, $article->getId() - 3, [], ['id' => 'ASC']]],
            [$A, 'findByParent', [$parent, [], ['id' => 'ASC']]],
            [$A, 'findByParent', [[$parent, $article->getId()], [], ['id' => 'ASC']]],
            [$A, 'findByParent', [[], [], ['id' => 'ASC'], 5]],
            [$A, 'findByParentNotEqualTo', [$parent, [], ['id' => 'ASC']]],
            [$A, 'findByParentNotNull', [[], ['id' => 'ASC']]],
            [$A, 'findByTags', [$tags ?: [0], [], ['id' => 'ASC']]],
            [$A, 'findByTags', [$tags[0] ?? 0, [], ['id' => 'ASC']]],
            [$A, 'findByStateAndTags', [$published, $tags, [], ['id' => 'ASC']]],
            [$A, 'findBy', [['parent' => \Base\Database\Repository\Finder\Key::of($parent)], ['id' => 'ASC']]],
            [$A, 'findBy', [['parent' => \Base\Database\Repository\Finder\Key::of(array_values(array_filter([$parent, $article->getId()])))], ['id' => 'ASC']]],
            [$A, 'findByParentNotEqualTo', [\Base\Database\Repository\Finder\Key::of(null), [], ['id' => 'ASC']]],
            [$A, 'findBy', [[\Base\Database\Repository\Finder\Group::by('SlugStartingWithOrSlugStartingWith', substr($slug, 0, 2), 'z'), 'state' => $published], ['id' => 'ASC']]],
            [$A, 'findByStateOrState', [\Base\Enum\ThreadState::DRAFT, \Base\Enum\ThreadState::PUBLISH, [\Base\Database\Repository\Finder\Group::by('ParentNullAndPublishedAtNotNull')], ['id' => 'ASC']]],
            [$A, 'count', [[\Base\Database\Repository\Finder\Group::by('ParentNotNullOrStateEqualTo', \Base\Enum\ThreadState::DRAFT)]]],
            // Groups built from their members (Group::of(), step B1b): the back office's search, filters and neighbour.
            ...(method_exists(\Base\Database\Repository\Finder\Group::class, 'of') ? self::builtGroups($A, $U, $article, $older) : []),
            // The paths: through the parent, the tags, and the translations (the Translatable join).
            [$A, 'findBy', [['parent.slug' => $article->getParent()?->getSlug()], ['id' => 'ASC']]],
            [$A, 'findBy', [['tags.slug' => $article->getTags()->first() ? $article->getTags()->first()->getSlug() : 'none'], ['id' => 'ASC']]],
            [$A, 'findBy', [['translations.title' => $title], ['id' => 'ASC']]],
            [$A, 'findBy', [['state' => $published], ['translations.title' => 'ASC', 'id' => 'ASC'], 15]],
            [$A, 'findByInsensitivePartialTranslations', ['%'.mb_strtolower(mb_substr($title, 0, 3)).'%', [], ['id' => 'ASC']]],
            [$A, 'findBy', [['translations.locale' => 'fr'], ['id' => 'ASC'], 10]],
            [\Base\Entity\ThreadIntl::class, 'findByTranslatableNotNull', [['translatable.state' => $published], ['id' => 'ASC'], 10]],
            [\Base\Entity\ThreadIntl::class, 'findBy', [['translatable.slug' => $slug], ['id' => 'ASC']]],
            [$A, 'findBy', [['state' => $published], ['parent.slug' => 'ASC', 'id' => 'DESC'], 10]],
            [$A, 'findBy', [[], ['slug' => 'DESC'], 4, 3]],
            [$A, 'findBy', [['state' => $published], ['id' => [$article->getId(), $older->getId()]], 5]],
            [$A, 'findBy', [['state' => $published], ['state' => [\Base\Enum\ThreadState::DRAFT, \Base\Enum\ThreadState::PUBLISH], 'id' => 'ASC'], 5], false, 'An order on a list of texts: omnibridge/doctrine writes FIELD(e.state,STATE_DRAFT,STATE_PUBLISH), the values unquoted, which DQL reads as names (a semantical error); EloquentFinder binds them.'],
            [$A, 'findBy', [[], ['publishedAt' => 'ASC', 'id' => 'ASC'], null, 5]],
            [$A, 'findAtMost2ByState', [$published, [], ['id' => 'DESC']]],
            [$A, 'findNextOneByState', [['publishedAt' => $older->getPublishedAt()], $published]],
            [$A, 'findPreviousOneByState', [['publishedAt' => new \DateTime('now')], $published]],
            [$A, 'findLastOneByState', [$published, [], ['publishedAt' => 'DESC']]],
            [$A, 'findOneBySlugWithRoute', [['slug' => $slug], 'slug']],
            [$A, 'findEagerlyByState', [['destination'], $published, [], ['id' => 'ASC']]],
            [$A, 'countForTagsByState', [$published]],
            [$A, 'distinctCountForTagsByState', [$published]],
            [$A, 'countByState', [$published, [], '', null, ['parent']], true],
            [$A, 'countByState', [$published, [], '', null, ['state']], true],
            [$A, 'countForParentByState', [$published]],
            [$A, 'lengthOfSlugByState', [$published, [], ['id' => 'ASC']]],
            [$A, 'lengthOfTagsByState', [$published, [], ['id' => 'ASC']]],
            [$A, 'maxOfPublishedAt', []],
            [$A, 'minOfIdByState', [$published]],
            [$A, 'sumOfIdByStateNotEqualTo', [\Base\Enum\ThreadState::PUBLISH]],
            [$A, 'sumOfIdByState', ['nothing']],
            [$A, 'findByModel', [(new \App\Entity\Article\Article())->setSlug($slug)]],
            [$A, 'findByInsensitiveIdentifier', [strtoupper(substr($slug, 0, 3))], true],
            [$A, 'findRandomlyByState', [$published], true],
            [$U, 'findByIdNotEqualToAndActiveAtYoungerThan', [0, 10 * 365 * 86400, [], ['id' => 'ASC']]],
            [$U, 'cacheByRoles', [['ROLE_ADMIN']], true],
            [$U, 'findByPartialRoles', ['%ADMIN%', [], ['id' => 'ASC']]],
            [$U, 'findBy', [['roles' => ['ROLE_USER']], ['id' => 'ASC'], 5]],
            [\App\Entity\Article\Tag::class, 'findBySlug', [array_map(static fn (object $tag) => $tag->getSlug(), $article->getTags()->toArray())], true],
            [\App\Entity\Article\Tag::class, 'lengthOfThreadsBy', [[], ['slug' => 'ASC']]],
            [\App\Entity\Article\Comment::class, 'countByArticleAndStateAndWorkflow', [[$article->getId()], $published, \Base\Enum\WorkflowState::APPROVED, [], '', null, ['article']], true],
            [\App\Entity\Newsletter\Subscriber::class, 'findBy', [[], ['id' => 'ASC'], 5]],
        ];
        $scenarios = [];
        foreach ($calls as $index => $call) {
            [$class, $method, $arguments] = $call;
            $name = sprintf('%02d %s::%s(%s)', $index, substr($class, strrpos($class, '\\') + 1), $method, mb_substr((string) json_encode(FinderParity::normalise($arguments), \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR), 0, 100));
            $scenarios[$name] = [fn () => $this->doctrine($class)->{$method}(...$arguments), fn () => $this->eloquent($class)->{$method}(...$arguments), $call[3] ?? false, $call[4] ?? null];
        }
        $scenarios['findRandomlyByState (how many)'] = [
            fn () => \count($this->doctrine($A)->findRandomlyByState($published)->getResult()),
            fn () => \count($this->eloquent($A)->findRandomlyByState($published)->getResult()),
        ];

        return $scenarios;
    }

    /** @return array<string, array{0: \Closure(): mixed, 1: \Closure(): mixed, 2?: bool, 3?: string|null}> */
    private function siteScenarios(): array
    {
        $article = $this->latest(\App\Entity\Article\Article::class, ['state' => \Base\Enum\ThreadState::PUBLISH]);
        self::assertNotNull($article, 'the site has a published article');
        $user = $this->latest(\App\Entity\User::class);
        $comment = $this->latest(\App\Entity\Article\Comment::class);
        $subscriber = $this->latest(\App\Entity\Newsletter\Subscriber::class);
        $newsletter = $this->latest(\App\Entity\Newsletter\Newsletter::class);
        $engagement = $this->latest(\App\Entity\Newsletter\Engagement::class);
        $tag = $this->latest(\App\Entity\Article\Tag::class);
        $published = [\Base\Enum\ThreadState::PUBLISH];
        $tags = array_map(static fn (object $tag) => $tag->getId(), $article->getTags()->toArray());
        $approved = \Base\Enum\WorkflowState::APPROVED;

        // Both sides alike: [Doctrine's, Eloquent's] for a class and a call.
        $same = fn (string $class, \Closure $call, bool $unordered = false): array => [fn () => $call($this->doctrine($class)), fn () => $call($this->eloquent($class)), $unordered];
        // A method of the site's repository on Doctrine, its finder on Eloquent.
        $site = fn (string $class, \Closure $doctrine, \Closure $eloquent): array => [fn () => $doctrine($this->doctrine($class)), fn () => $eloquent($this->eloquent($class))];
        $articles = \App\Entity\Article\Article::class;
        $users = \App\Entity\User::class;
        $comments = \App\Entity\Article\Comment::class;
        $subscribers = \App\Entity\Newsletter\Subscriber::class;
        $threads = \Base\Entity\Thread::class;

        // countForAllCommentsIn(): the site's method (a cache, the visitor's states), its two grouped counts on Eloquent.
        $countForAllCommentsIn = static function (object $repository, array $articles): array {
            $nComments = [];
            $nDiscussions = $repository->countByArticleAndStateAndWorkflow($articles, [\Base\Enum\ThreadState::PUBLISH], \Base\Enum\WorkflowState::APPROVED, [], '', null, ['article']);
            foreach ($nDiscussions as $entry) {
                $nComments[$entry['article']] = ($nComments[$entry['article']] ?? 0) + $entry['count'];
            }
            $discussionIds = array_map(fn ($comment) => $comment['entity']->getId(), $nDiscussions);
            if ($discussionIds) {
                foreach ($repository->countByParentAndStateAndWorkflow($discussionIds, [\Base\Enum\ThreadState::PUBLISH], \Base\Enum\WorkflowState::APPROVED, [], '', null, ['parent']) as $entry) {
                    $parent = $entry['entity']->getParent()->getParent();
                    $nComments[$parent->getId()] = ($nComments[$parent->getId()] ?? 0) + $entry['count'];
                }
            }

            return $nComments;
        };
        $byKey = static function (array $map): array {
            ksort($map);

            return $map;
        };

        return [
            // The site's own calls.
            'users.findOneByEmailOrUsername' => $same($users, fn ($r) => $r->findOneByEmailOrUsername($user?->getEmail(), $user?->getEmail())),
            'users.findOneByEmailOrUsername(username)' => $same($users, fn ($r) => $r->findOneByEmailOrUsername($user?->getUsername(), $user?->getUsername())),
            'users.loadUserByIdentifier' => $site($users, fn ($r) => $r->loadUserByIdentifier((string) $user?->getEmail()), fn ($r) => $r->findOneByEmailOrUsername((string) $user?->getEmail(), (string) $user?->getEmail()) ?? throw new \RuntimeException('No user.')),
            'articles.oneByStateAndTagsAndIdNot' => $site($articles, fn ($r) => $r->oneByStateAndTagsAndIdNot($published, $tags, $article->getId()), fn ($r) => $r->cacheOneByStateAndTagsAndIdNotEqualTo($published, $tags, $article->getId())),
            'articles.oneByStateAndDestination' => $site($articles, fn ($r) => $r->oneByStateAndDestination($published, $article->getDestination(), ['publishedAt' => 'DESC']), fn ($r) => $r->cacheOneByStateAndDestination($published, $article->getDestination(), [], ['publishedAt' => 'DESC'])),
            'comments.countForAllCommentsIn(article)' => $site($comments, fn ($r) => $r->countForAllCommentsIn($article), fn ($r) => $countForAllCommentsIn($r, [$article])),
            'comments.countForAllCommentsIn(articles)' => $site($comments, fn ($r) => $byKey($r->countForAllCommentsIn($this->doctrine($articles)->byState($published))), fn ($r) => $byKey($countForAllCommentsIn($r, $this->doctrine($articles)->byState($published)))),
            'subscribers.oneByEmail' => $site($subscribers, fn ($r) => $r->oneByEmail($subscriber?->getEmail()), fn ($r) => $r->findOneByEmail($subscriber?->getEmail())),
            'subscribers.cachedOneByEmail' => $site($subscribers, fn ($r) => $r->cachedOneByEmail($subscriber?->getEmail()), fn ($r) => $r->cacheOneByEmail($subscriber?->getEmail())),
            'subscribers.findOneByEmailIncludingTrashed' => $site($subscribers, fn ($r) => $r->findOneByEmailIncludingTrashed($subscriber?->getEmail()), fn ($r) => $this->withoutTrash(fn () => $subscriber?->getEmail() ? ($r->findBy(['email' => $subscriber->getEmail()], null, 1)[0] ?? null) : null)),
            'newsletters.oneByUuid' => $site(\App\Entity\Newsletter\Newsletter::class, fn ($r) => null === $newsletter ? null : $r->oneByUuid((string) $newsletter->getUuid()), fn ($r) => null === $newsletter ? null : $r->findOneByUuid((string) $newsletter->getUuid())),
            'engagements.oneBySubscriberAndNewsletter' => $site(\App\Entity\Newsletter\Engagement::class, fn ($r) => null === $engagement ? null : $r->oneBySubscriberAndNewsletter($engagement->getSubscriber(), $engagement->getNewsletter()), fn ($r) => null === $engagement ? null : $r->findOneBySubscriberAndNewsletter($engagement->getSubscriber(), $engagement->getNewsletter())),
            'subscriptions.byThread' => $site(\App\Entity\Newsletter\Subscription::class, fn ($r) => $r->byThread($article), fn ($r) => $r->findByThread($article)->getResult()),
            'articles.countByStatePublishedYoungerThan(recent)' => $same($articles, fn ($r) => $r->countByStatePublishedYoungerThan(\Base\Enum\ThreadState::PUBLISH, 3600 * 48)),
            'articles.countByStatePublishedYoungerThan' => $same($articles, fn ($r) => $r->countByStatePublishedYoungerThan(\Base\Enum\ThreadState::PUBLISH, 10 * 365 * 86400)),
            'galleries.countByStatePublishedYoungerThan' => $same(\App\Entity\Gallery\Gallery::class, fn ($r) => $r->countByStatePublishedYoungerThan(\Base\Enum\ThreadState::PUBLISH, 10 * 365 * 86400)),
            // The core's calls on the site's threads (Threads).
            'threads.oneBySlug' => $same($threads, fn ($r) => $r->oneBySlug($article->getSlug())),
            'threads.oneBySlug(uncached)' => $same($threads, fn ($r) => $r->oneBySlug($article->getSlug(), false)),
            'articles.oneBySlugAndState' => $same($articles, fn ($r) => $r->oneBySlugAndState($article->getSlug(), $published)),
            'articles.oneByState' => $same($articles, fn ($r) => $r->oneByState($published, ['publishedAt' => 'DESC'])),
            'articles.byState' => $same($articles, fn ($r) => $r->byState($published, ['publishedAt' => 'DESC'])),
            'articles.byParentAndState' => $same($articles, fn ($r) => $r->byParentAndState($article->getParent(), $published, ['id' => 'ASC'], 5, 2)),
            'articles.byParentAndStatePublishedWithin' => $same($articles, fn ($r) => $r->byParentAndStatePublishedWithin($article->getParent(), $published, '+8 days', ['publishedAt' => 'DESC'])),
            'articles.oneByStateAndSlugStartingWith' => $same($articles, fn ($r) => $r->oneByStateAndSlugStartingWith($published, substr((string) $article->getSlug(), 0, 3))),
            'articles.previousOne' => $same($articles, fn ($r) => $r->previousOne(['publishedAt' => $article->getPublishedAt()], $published, \App\Entity\Article\Article::class)),
            'articles.nextOne' => $same($articles, fn ($r) => $r->nextOne(['id' => $article->getId()], $published, \App\Entity\Article\Article::class)),
            'threads.previousOne(any class)' => $same($threads, fn ($r) => $r->previousOne(['id' => $article->getId()], $published)),
            'articles.countForChildrenIn' => $same($articles, fn ($r) => $r->countForChildrenIn($article)),
            'tags.cacheByInsensitiveIdentifier' => $same(\App\Entity\Article\Tag::class, fn ($r) => null === $tag ? null : $r->cacheByInsensitiveIdentifier(strtoupper((string) $tag->getSlug()))),
            // One of each word, by name.
            'findOneBySlug' => $same($articles, fn ($r) => $r->findOneBySlug($article->getSlug())),
            'cacheOneBySlug' => $same($articles, fn ($r) => $r->cacheOneBySlug($article->getSlug())),
            'findByState' => $same($articles, fn ($r) => $r->findByState($published, [], ['id' => 'ASC'])),
            'findByStateAndDestination' => $same($articles, fn ($r) => $r->findByStateAndDestination($published, $article->getDestination(), [], ['id' => 'ASC'])),
            'findBySlugOrId' => $same($articles, fn ($r) => $r->findBySlugOrId($article->getSlug(), $comment?->getId() ?? 0, [], ['id' => 'ASC'])),
            'findByIdGreaterThan' => $same($articles, fn ($r) => $r->findByIdGreaterThan($article->getId() - 50, [], ['id' => 'ASC'])),
            'findByIdNotEqualTo' => $same($articles, fn ($r) => $r->findByStateAndIdNotEqualTo($published, $article->getId(), [], ['id' => 'ASC'])),
            'findByPublishedAtYoungerThan' => $same($articles, fn ($r) => $r->findByStateAndPublishedAtYoungerThan($published, 10 * 365 * 86400, [], ['id' => 'ASC'])),
            'findByPublishedAtOlderThan' => $same($articles, fn ($r) => $r->findByPublishedAtOlderThan(86400, [], ['id' => 'ASC'])),
            'findByPublishedAtIsOver' => $same($articles, fn ($r) => $r->findByStateAndPublishedAtIsOver($published, [], ['id' => 'ASC'])),
            'findByParentNull' => $same($articles, fn ($r) => $r->findByStateAndParentNull($published, [], ['id' => 'ASC'])),
            'findBySlugStartingWith' => $same($articles, fn ($r) => $r->findBySlugStartingWith(substr((string) $article->getSlug(), 0, 2), [], ['id' => 'ASC'])),
            'findBySlugEndingWith' => $same($articles, fn ($r) => $r->findBySlugEndingWith(substr((string) $article->getSlug(), -2), [], ['id' => 'ASC'])),
            'findByInsensitiveSlug' => $same($articles, fn ($r) => $r->findByInsensitiveSlug(strtoupper((string) $article->getSlug())), true),
            'findByPartialSlug' => $same($articles, fn ($r) => $r->findByPartialSlug(substr((string) $article->getSlug(), 1, 4), [], ['id' => 'ASC'])),
            'findByStateAndBut' => $same($articles, fn ($r) => $r->findByStateAndBut($published, $article->getId(), [], ['id' => 'ASC'])),
            'findByStateAndInstanceOf' => $same($threads, fn ($r) => $r->findByStateAndInstanceOf($published, \App\Entity\Article\Article::class, [], ['id' => 'ASC'])),
            'findByStateAndClassOf' => $same($threads, fn ($r) => $r->findByStateAndClassOf($published, \App\Entity\Gallery\Gallery::class, [], ['id' => 'ASC'])),
            'findAtMost3ByState' => $same($articles, fn ($r) => $r->findAtMost3ByState($published, [], ['id' => 'ASC'])),
            'findLast2ByState' => $same($articles, fn ($r) => $r->findLast2ByState($published)),
            'findLastOneByState' => $same($articles, fn ($r) => $r->findLastOneByState($published)),
            'findPreviousByState' => $same($articles, fn ($r) => $r->findPrevious2ByState(['id' => $article->getId()], $published)),
            'findNextByState' => $same($articles, fn ($r) => $r->findNext2ByState(['id' => $article->getId() - 20], $published)),
            'findAll' => $same(\App\Entity\Destination\Destination::class, fn ($r) => $r->findAll(), true),
            'cacheAll' => $same(\App\Entity\Destination\Destination::class, fn ($r) => $r->cacheAll(['id' => 'DESC'])),
            'countByState' => $same($articles, fn ($r) => $r->countByState($published)),
            'distinctCountByState' => $same($articles, fn ($r) => $r->distinctCountByState($published)),
            'countByParentAndStateAndWorkflow' => $same($comments, fn ($r) => $r->countByParentAndStateAndWorkflow([$article->getId()], $published, $approved, [], '', null, ['parent']), true),
            'users.findByActiveAtYoungerThan' => $same($users, fn ($r) => $r->findByActiveAtYoungerThan(10 * 365 * 86400, [], ['id' => 'ASC'])),
            'users.cacheOneByUsername' => $same($users, fn ($r) => $r->cacheOneByUsername($user?->getUsername())),
            'subscribers.findOneByEmail' => $same($subscribers, fn ($r) => $r->findOneByEmail($subscriber?->getEmail())),
            'findRandomlyByState (how many)' => $same($articles, fn ($r) => \count($r->findRandomlyByState($published)->getResult())),
        ];
    }

    /** @return array<string, array{0: \Closure(): mixed, 1: \Closure(): mixed}> */
    private function rewrittenScenarios(): array
    {
        $since = new \DateTimeImmutable('-2 years');
        $until = new \DateTimeImmutable('-6 months');
        $articles = \App\Entity\Article\Article::class;
        $newsletters = \App\Entity\Newsletter\Newsletter::class;

        return $this->handWritten(null) + [
            // The site's own (App\Repository\*, rewritten at step E2): its method on Doctrine, its finder on Eloquent.
            'Articles::publishedBetween' => [fn () => $this->doctrine($articles)->publishedBetween($since), fn () => $this->eloquent($articles)->findByPublishedAtGreaterEqualToAndState($since, \Base\Enum\ThreadState::PUBLISH)->getResult()],
            'Articles::publishedBetween(until)' => [fn () => $this->doctrine($articles)->publishedBetween($since, $until), fn () => $this->eloquent($articles)->findByPublishedAtGreaterEqualToAndStateAndPublishedAtLowerEqualTo($since, \Base\Enum\ThreadState::PUBLISH, $until)->getResult()],
            'Newsletters::latest' => [fn () => $this->doctrine($newsletters)->latest(3), fn () => $this->eloquent($newsletters)->findBy([], ['createdAt' => 'DESC'], 3)],
        ];
    }

    /**
     * HandWrittenQueries' methods (the site's copy of the core's), each on both: its "after" call on Doctrine's
     * repositories and on Eloquent's.
     *
     * @return array<string, array{0: \Closure(): mixed, 1: \Closure(): mixed}>
     */
    private function handWritten(?object $commented): array
    {
        $entityManager = $this->entityManager;
        $latest = static function (string $class) use ($entityManager): ?object {
            $id = $entityManager->getClassMetadata($class)->getSingleIdentifierFieldName();

            return $entityManager->createQueryBuilder()->select('x')->from($class, 'x')->orderBy('x.'.$id, 'DESC')->setMaxResults(1)->getQuery()->getOneOrNullResult();
        };
        $doctrine = \App\Tests\Repository\HandWrittenQueries::scenarios(fn (string $class) => $this->doctrine($class), $latest, \App\Entity\User::class, null, $commented);
        $eloquent = \App\Tests\Repository\HandWrittenQueries::scenarios(fn (string $class) => $this->eloquent($class), $latest, \App\Entity\User::class, null, $commented);
        $scenarios = [];
        foreach ($doctrine as $name => [, $after]) {
            $scenarios[$name] = [$after, $eloquent[$name][1]];
        }

        return $scenarios;
    }

    private function doctrine(string $class): EntityRepository
    {
        return $this->entityManager->getRepository($class);
    }

    private function eloquent(string $class): object
    {
        return $this->eloquent->repositories()->of($class);
    }

    private function latest(string $class, array $criteria = []): ?object
    {
        $repository = $this->entityManager->getRepository($class);

        // Doctrine's own findBy() (EntityRepository's, no finder of the language), as the site's test reads them.
        return (new \ReflectionMethod(EntityRepository::class, 'findBy'))->invoke($repository, $criteria, ['id' => 'DESC'], 1)[0] ?? null;
    }

    private function withoutTrash(\Closure $read): mixed
    {
        $backend = $this->eloquent->backend();
        $enabled = $backend->isScopeEnabled('trash');
        $enabled && $backend->disableScope('trash');
        try {
            return $read();
        } finally {
            $enabled && $backend->enableScope('trash');
        }
    }

    private function inTransaction(\Closure $work): mixed
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            return $work();
        } finally {
            $connection->rollBack();
            $this->entityManager->clear();
            $this->clear();
        }
    }

    /**
     * The store forgets what it read between two scenarios; Doctrine's entity manager keeps its entities, as the site's
     * test keeps them (a call given an entity cleared from it - countForAllCommentsIn()'s cache key - would fail).
     */
    private function clear(): void
    {
        $this->eloquent->store()->clear();
    }

    /** @param array<string, array{outcome: string}> $report */
    private function record(string $part, array $report): void
    {
        $file = static::getContainer()->getParameter('kernel.project_dir').'/var/eloquent-finder-parity-site.json';
        $all = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
        $all[$part] = ['counts' => FinderParity::counts($report), 'scenarios' => $report];
        file_put_contents($file, json_encode($all, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR)."\n");
        self::assertSame([], array_keys(FinderParity::unequal($report)), $part.': '.json_encode(FinderParity::unequal($report), \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR));
    }
}
