<?php

namespace Omnibridge\Eloquent\Tests\Site;

require_once __DIR__.'/autoload.php';

use Doctrine\ORM\EntityManagerInterface;
use Omnibridge\Eloquent\Omnibase\EloquentPersistence;
use Omnibridge\Eloquent\Tests\Support\FinderParity;
use Omnibridge\Eloquent\Tests\Support\OmnibaseHarness;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * lesringostars' own repositories on both backends (omnisense/omnibase's plan "omnibase agnostic", step F7f): each
 * method of its eleven App\Repository interfaces - and the accounts' provider, by address and by name - asked of
 * its Doctrine repository (App\Repository\*) and of its Eloquent one (App\Persistence\Eloquent\*, registered as
 * base.persistence: eloquent registers them), on the site's database: the same answers (FinderParity's
 * outcomes). Reads only. The comments' counts each on a cache of their own, so neither answers from the other's.
 * The report goes to var/eloquent-app-repositories.json.
 *
 *     OMNIBRIDGE_ELOQUENT_SITE=1 php bin/phpunit vendor/omnibridge/eloquent/tests/Site/AppRepositoriesParityTest.php
 */
final class AppRepositoriesParityTest extends KernelTestCase
{
    private const REGISTRAR = 'App\\Persistence\\Eloquent\\Repositories';

    private EntityManagerInterface $entityManager;

    private EloquentPersistence $eloquent;

    protected function tearDown(): void
    {
        // The Eloquent side's entity dispatchers let go (they are live ones: AbstractEventDispatcher::live()).
        gc_collect_cycles();
        parent::tearDown();
    }

    protected function setUp(): void
    {
        if (!getenv('OMNIBRIDGE_ELOQUENT_SITE') || !class_exists(self::REGISTRAR)) {
            self::markTestSkipped('Run in lesringostars\' suite (OMNIBRIDGE_ELOQUENT_SITE=1), whose App\\Persistence\\Eloquent holds its repositories on Eloquent.');
        }
        self::bootKernel();
        $this->entityManager = static::getContainer()->get('doctrine')->getManager();
        // With omnibase's lifecycle, its AttributeReader among it, as a host gives it: #[Alias] read (Article::getComments()).
        $this->eloquent = OmnibaseHarness::persistence(static::getContainer());
        (new (self::REGISTRAR)(new ArrayAdapter()))->register($this->eloquent->repositories());
    }

    public function testTheSitesRepositoriesAnswerAlikeOnBothBackends(): void
    {
        $em = $this->entityManager;
        $connection = $em->getConnection();
        $repositories = $this->eloquent->repositories();
        $doctrine = static fn (string $entity): object => $em->getRepository($entity);
        $eloquent = static fn (string $interface, string $entity): object => $repositories->implementing($interface, $entity);
        $classes = \constant(self::REGISTRAR.'::INTERFACES');
        $table = static fn (string $entity): string => $em->getClassMetadata($entity)->getTableName();
        $first = static fn (string $entity, string $order = 'id ASC') => ($id = $connection->fetchOne('SELECT id FROM '.$table($entity).' ORDER BY '.$order.' LIMIT 1')) ? $em->find($entity, $id) : null;

        // An entity given to a method is each side's own: read again by that side when the call is made (a reference
        // [class, id] among the arguments), so that neither hands the other what its unit of work manages.
        $resolve = static function (string $side, mixed $value) use (&$resolve, $em, $repositories): mixed {
            if ($value instanceof \ArrayObject) {
                [$class, $id] = $value->getArrayCopy();

                return 'doctrine' === $side ? $em->find($class, $id) : $repositories->of($class)->find($id);
            }

            return \is_array($value) ? array_map(static fn ($member) => $resolve($side, $member), $value) : $value;
        };
        $ref = static fn (?object $entity): ?\ArrayObject => null === $entity ? null : new \ArrayObject([$em->getClassMetadata($entity::class)->getName(), $entity->getId()]);
        $scenarios = [];
        $ask = static function (string $name, string $interface, string $method, array $arguments) use (&$scenarios, $doctrine, $eloquent, $classes, $resolve): void {
            $entity = $classes[$interface] ?? \App\Entity\User::class;
            $scenarios[$name] = [
                static fn () => $doctrine($entity)->{$method}(...$resolve('doctrine', $arguments)),
                static fn () => $eloquent($interface, $entity)->{$method}(...$resolve('eloquent', $arguments)),
            ];
        };

        $published = \Base\Enum\ThreadState::PUBLISH;
        $article = $first(\App\Entity\Article\Article::class, 'id DESC');
        $articleIds = array_map('intval', $connection->fetchFirstColumn('SELECT a.id FROM article a JOIN thread t ON t.id = a.id ORDER BY a.id DESC LIMIT 12'));
        $articles = array_values(array_filter(array_map(static fn (int $id) => $ref($em->find(\App\Entity\Article\Article::class, $id)), $articleIds)));
        $destination = $ref($article?->getDestination() ?? $first(\App\Entity\Destination\Destination::class));
        $tags = $article ? array_map($ref, array_values($article->getTags()->toArray())) : [];
        $articleId = $article?->getId();
        $article = $ref($article);
        $year = new \DateTimeImmutable('-1 year');

        $ask('Articles::oneByStateAndTagsAndIdNot', \App\Repository\Article\Articles::class, 'oneByStateAndTagsAndIdNot', [$published, $tags, $articleId]);
        $ask('Articles::oneByStateAndDestination', \App\Repository\Article\Articles::class, 'oneByStateAndDestination', [$published, $destination, ['publishedAt' => 'DESC']]);
        $ask('Articles::oneByStateAndDestination, no destination', \App\Repository\Article\Articles::class, 'oneByStateAndDestination', [$published, null, ['publishedAt' => 'ASC']]);
        $ask('Articles::publishedBetween, a year', \App\Repository\Article\Articles::class, 'publishedBetween', [$year]);
        $ask('Articles::publishedBetween, a year to a month ago', \App\Repository\Article\Articles::class, 'publishedBetween', [$year, new \DateTimeImmutable('-1 month')]);
        foreach (['twelve articles' => $articles, 'one article' => $article] as $which => $given) {
            $scenarios["Comments::countForAllCommentsIn, {$which}"] = [
                static fn () => (new \App\Repository\Article\CommentRepository(new ArrayAdapter(), static::getContainer()->get('doctrine')))->countForAllCommentsIn($resolve('doctrine', $given)),
                static fn () => $eloquent(\App\Repository\Article\Comments::class, \App\Entity\Article\Comment::class)->countForAllCommentsIn($resolve('eloquent', $given)),
            ];
        }
        $ask('Tags, the first twenty', \App\Repository\Article\Tags::class, 'findBy', [[], ['id' => 'ASC'], 20]);
        foreach (['fr-FR', 'en-GB', 'ja-JP'] as $locale) {
            $ask("Calendars::texts, twelve articles, {$locale}", \App\Repository\Calendar\Calendars::class, 'texts', [$articles, $locale]);
        }
        $ask('Calendars::texts, none', \App\Repository\Calendar\Calendars::class, 'texts', [[], 'fr-FR']);
        $ask('Destinations::subscribable', \App\Repository\Destination\Destinations::class, 'subscribable', []);
        $ask('Galleries, the first ten', \App\Repository\Gallery\Galleries::class, 'findBy', [[], ['id' => 'ASC'], 10]);
        $ask('Guestbooks, all', \App\Repository\Guestbook\Guestbooks::class, 'findBy', [[], ['id' => 'ASC']]);

        $newsletter = $first(\App\Entity\Newsletter\Newsletter::class, 'id DESC');
        $ask('Newsletters::oneByUuid', \App\Repository\Newsletter\Newsletters::class, 'oneByUuid', [(string) $newsletter?->getUuid()]);
        $ask('Newsletters::oneByUuid, unknown', \App\Repository\Newsletter\Newsletters::class, 'oneByUuid', ['00000000-0000-0000-0000-000000000000']);
        $ask('Newsletters::latest, 5', \App\Repository\Newsletter\Newsletters::class, 'latest', [5]);
        $ask('Newsletters::recipientsPerNewsletter', \App\Repository\Newsletter\Newsletters::class, 'recipientsPerNewsletter', []);

        $emails = array_map('strval', array_column($em->createQuery('SELECT s.email FROM '.\App\Entity\Newsletter\Subscriber::class.' s ORDER BY s.id ASC')->setMaxResults(12)->getArrayResult(), 'email'));
        foreach ([...$emails, 'nobody-f7f@example.org', null] as $i => $email) {
            $ask("Subscribers::oneByEmail #{$i}", \App\Repository\Newsletter\Subscribers::class, 'oneByEmail', [$email]);
            $ask("Subscribers::findOneByEmailIncludingTrashed #{$i}", \App\Repository\Newsletter\Subscribers::class, 'findOneByEmailIncludingTrashed', [$email]);
        }
        $ask('Subscriptions::byThread', \App\Repository\Newsletter\Subscriptions::class, 'byThread', [$destination]);

        $engagement = $first(\App\Entity\Newsletter\Engagement::class, 'id DESC');
        if (null !== $engagement && null !== $engagement->getSubscriber() && null !== $engagement->getNewsletter()) {
            $ask('Engagements::oneBySubscriberAndNewsletter', \App\Repository\Newsletter\Engagements::class, 'oneBySubscriberAndNewsletter', [$ref($engagement->getSubscriber()), $ref($engagement->getNewsletter())]);
        }
        $ask('Engagements::clicksPerNewsletter', \App\Repository\Newsletter\Engagements::class, 'clicksPerNewsletter', []);
        foreach ([5, 0, 50] as $limit) {
            $ask("Engagements::topReaders, {$limit}", \App\Repository\Newsletter\Engagements::class, 'topReaders', [$limit]);
        }

        $accounts = $em->createQuery('SELECT u.email, u.username FROM '.\App\Entity\User::class.' u ORDER BY u.id ASC')->setMaxResults(5)->getArrayResult();
        foreach ($accounts as $i => $account) {
            foreach (['email', 'username'] as $by) {
                $ask("Users::loadUserByIdentifier #{$i}, by {$by}", \Base\Repository\Users::class, 'loadUserByIdentifier', [(string) $account[$by]]);
            }
        }
        $ask('Users::loadUserByIdentifier, unknown', \Base\Repository\Users::class, 'loadUserByIdentifier', ['nobody-f7f']);

        // Between the two sides: neither answers from what the other read.
        $report = FinderParity::compare($scenarios, function (): void {
            $this->entityManager->clear();
            $this->eloquent->store()->clear();
        });
        FinderParity::write(static::getContainer()->getParameter('kernel.project_dir').'/var/eloquent-app-repositories.json', $report);

        self::assertSame([], FinderParity::unequal($report));
        foreach ($report as $name => $entry) {
            $expected = str_ends_with($name, 'unknown') && str_starts_with($name, 'Users::') ? FinderParity::REFUSED : FinderParity::EQUAL;
            self::assertSame($expected, $entry['outcome'], $name);
        }
        self::assertGreaterThan(40, \count($report));
    }
}
