<?php

namespace Omnibridge\Eloquent\Tests\Site;

require_once __DIR__.'/autoload.php';

use Base\Auth\Account\Passwords;
use Base\Auth\Account\Verification;
use Base\Auth\Listener\TrackConnections;
use Base\Auth\Listener\UpdateAccount;
use Base\Auth\Listener\WarnNewDevice;
use Base\Auth\Registration;
use Base\Auth\UserAccounts;
use Base\Auth\UserLinkTokens;
use Base\Auth\UserPasswords;
use Base\Database\Entity\EntityHydrator;
use Base\Form\Definition\FormProcessor;
use Base\Form\Definition\FormSubmission;
use Base\Notifier\NotifierInterface;
use Base\Repository\Layout\Settings;
use Base\Repository\User\Connections;
use Base\Repository\User\Tokens;
use Base\Repository\Users;
use Base\Request\Rule\Presence;
use Base\Routing\AdvancedRouterInterface;
use Base\Security\UserTracker;
use Base\Service\LocalizerInterface;
use Base\Service\ObfuscatorInterface;
use Base\Service\ParameterBagInterface;
use Base\Service\SecurityPolicy;
use Base\Service\SettingBag;
use Base\Service\SpamCheckerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Omniauth\ArrayState;
use Omniauth\Context;
use Omniauth\Event\SignedIn;
use Omniauth\Event\SignInFailed;
use Omniauth\Factor;
use Omniauth\Link\LinkMethod;
use Omniauth\Method\Methods;
use Omniauth\Omniauth;
use Omniauth\Password\PasswordMethod;
use Omnibridge\Contract\Translations;
use Omnibridge\Contract\Validation;
use Omnibridge\Eloquent\Tests\Support\OmnibaseHarness;
use Omnibridge\Eloquent\Tests\Support\RowChanges;
use Omnibridge\Eloquent\Tests\Support\Site\CapturingSender;
use Omnibridge\Eloquent\Tests\Support\Site\FlowAccess;
use Omnibridge\Eloquent\Tests\Support\Site\FlowSide;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * lesringostars' write flows, once through Doctrine and once through Eloquent, their rows compared column by column
 * (plan "omnibase agnostic", step F7c, third part). Each flow calls the services lesringostars' controllers call -
 * omnibase's rules (Registration, Verification, Passwords, omniauth's stores and the sign-in's listeners, Presence),
 * omnibase's neutral FormProcessor and EntityHydrator on lesringostars' form definitions, the site's CommentPosting,
 * NewsletterSubscription, newsletter actions, SettingBag - each made with the backend's unit of work and repositories
 * (FlowSide), no HTTP (step F7d's swap). Every table is fingerprinted before and after each flow (RowChanges): the
 * rows inserted, updated and deleted are what the flow wrote.
 *
 * Each backend runs on its own copy of lesringostars' test database (one dump, so the same rows and auto-increments:
 * the identifiers compare as written), in a process of its own, then the two records are compared:
 *
 *     DOCTRINE_TEST_DATABASE=f7c3_doctrine OMNIBRIDGE_ELOQUENT_FLOWS=doctrine php bin/phpunit vendor/omnibridge/eloquent/tests/Site/FlowParityTest.php
 *     DOCTRINE_TEST_DATABASE=f7c3_eloquent OMNIBRIDGE_ELOQUENT_FLOWS=eloquent php bin/phpunit vendor/omnibridge/eloquent/tests/Site/FlowParityTest.php
 *     OMNIBRIDGE_ELOQUENT_FLOWS=compare php bin/phpunit vendor/omnibridge/eloquent/tests/Site/FlowParityTest.php
 *
 * The flows refuse to write anywhere but a database named f7c3_*.
 */
final class FlowParityTest extends KernelTestCase
{
    public const PASSWORD = 'A-long-Passphrase-42!';
    public const NEW_PASSWORD = 'Another-long-Passphrase-43!';
    public const EMAIL = 'f7c3.flows@example.org';
    public const AGENT = 'Mozilla/5.0 (X11; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0';

    private FlowSide $side;

    private RowChanges $rows;

    private RequestStack $requests;

    private FlowAccess $access;

    /** @var array<string, mixed> what the flows read back, beside their rows */
    private array $reads = [];

    /** @var array<string, string> $_SERVER's values the flows change, put back */
    private array $server = [];

    private int $doctrineFlushes = 0;

    /** @var array<string, array<string, mixed>> the current flow's steps: what each wrote */
    private array $steps = [];

    /** @var array<string, array<string, string>> the fingerprints at the last step */
    private array $previous = [];

    private \DateTimeImmutable $start;

    protected function setUp(): void
    {
        $mode = (string) getenv('OMNIBRIDGE_ELOQUENT_FLOWS');
        if ('' === $mode || !class_exists('App\\Entity\\Article\\Article')) {
            self::markTestSkipped('Run in lesringostars\' suite (OMNIBRIDGE_ELOQUENT_FLOWS=doctrine|eloquent|compare, each backend on its f7c3_ database).');
        }
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        foreach ($this->server as $key => $value) {
            $_SERVER[$key] = $value;
        }
        unset($this->side);
        gc_collect_cycles();
        parent::tearDown();
    }

    public function testEachFlowWritesItsRows(): void
    {
        $backend = (string) getenv('OMNIBRIDGE_ELOQUENT_FLOWS');
        if (!\in_array($backend, ['doctrine', 'eloquent'], true)) {
            self::markTestSkipped('The flows run with OMNIBRIDGE_ELOQUENT_FLOWS=doctrine or eloquent.');
        }
        $container = static::getContainer();
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get('doctrine')->getManager();
        $database = (string) $entityManager->getConnection()->getDatabase();
        self::assertStringStartsWith('f7c3_', $database, 'the flows write a scratch copy only (DOCTRINE_TEST_DATABASE=f7c3_...)');

        // A Doctrine flush that writes anything during the Eloquent run is counted: it must stay at 0.
        $entityManager->getEventManager()->addEventListener([Events::onFlush], new class($this) {
            public function __construct(private readonly FlowParityTest $test)
            {
            }

            public function onFlush(\Doctrine\ORM\Event\OnFlushEventArgs $args): void
            {
                $unitOfWork = $args->getObjectManager()->getUnitOfWork();
                if ($unitOfWork->getScheduledEntityInsertions() || $unitOfWork->getScheduledEntityUpdates() || $unitOfWork->getScheduledEntityDeletions() || $unitOfWork->getScheduledCollectionUpdates() || $unitOfWork->getScheduledCollectionDeletions()) {
                    $this->test->doctrineFlushed();
                }
            }
        });

        $this->side = 'doctrine' === $backend ? FlowSide::doctrine($container) : FlowSide::eloquent($container, OmnibaseHarness::persistence($container));
        $this->rows = new RowChanges($entityManager->getConnection()->getNativeConnection());
        $this->access = new FlowAccess();
        $this->requests = new RequestStack();
        $this->browser('f7c3-browser-1', '203.0.113.71');

        $record = ['backend' => $backend, 'database' => $database, 'flows' => []];
        foreach (['signUp', 'signIn', 'comment', 'newsletter', 'trashAndVault', 'tagsAndOrder', 'settings'] as $flow) {
            $this->side->clear();
            $this->reads = [];
            $this->steps = [];
            $this->start = new \DateTimeImmutable();
            $before = $this->previous = $this->rows->snapshot();
            $error = null;
            try {
                $this->{$flow}();
            } catch (\Throwable $e) {
                $error = $e::class.': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine();
            }
            $this->step('(end)');
            $record['flows'][$flow] = ['error' => $error, 'reads' => $this->reads, 'steps' => array_filter($this->steps), 'rows' => $this->rows->changes($before, $this->previous, $this->start)];
        }
        $record['doctrine_flushes'] = $this->doctrineFlushes;

        $file = self::file($backend);
        @mkdir(\dirname($file), 0777, true);
        file_put_contents($file, json_encode($record, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR)."\n");

        foreach ($record['flows'] as $flow => $result) {
            self::assertNull($result['error'], $flow);
            self::assertNotSame([], $result['rows'], $flow.' writes rows');
        }
        if ('eloquent' === $backend) {
            self::assertSame(0, $this->doctrineFlushes, 'nothing written through Doctrine during the Eloquent run');
        }
    }

    public function testBothBackendsWroteTheSameRows(): void
    {
        if ('compare' !== getenv('OMNIBRIDGE_ELOQUENT_FLOWS')) {
            self::markTestSkipped('The comparison runs with OMNIBRIDGE_ELOQUENT_FLOWS=compare, after both backends.');
        }
        $doctrine = json_decode((string) @file_get_contents(self::file('doctrine')), true);
        $eloquent = json_decode((string) @file_get_contents(self::file('eloquent')), true);
        self::assertIsArray($doctrine, 'Doctrine\'s record');
        self::assertIsArray($eloquent, 'Eloquent\'s record');

        $report = [];
        foreach ($doctrine['flows'] as $flow => $left) {
            $right = $eloquent['flows'][$flow] ?? null;
            $differences = [];
            foreach (['(flow)' => [$left['rows'], $right['rows'] ?? []]] + array_map(static fn (string $step): array => [$left['steps'][$step] ?? [], $right['steps'][$step] ?? []], array_combine($names = array_values(array_unique([...array_keys($left['steps'] ?? []), ...array_keys($right['steps'] ?? [])])), $names)) as $step => [$l, $r]) {
                foreach (array_unique([...array_keys($l), ...array_keys($r)]) as $table) {
                    foreach (['inserted', 'updated', 'deleted'] as $kind) {
                        $a = $l[$table][$kind] ?? [];
                        $b = $r[$table][$kind] ?? [];
                        if ($a !== $b) {
                            $differences[$step][$table][$kind] = self::columns($a, $b);
                        }
                    }
                }
            }
            if (($left['reads'] ?? []) !== ($right['reads'] ?? [])) {
                $differences['(reads)'] = ['doctrine' => $left['reads'] ?? [], 'eloquent' => $right['reads'] ?? []];
            }
            if ($left['error'] !== ($right['error'] ?? null)) {
                $differences['(error)'] = ['doctrine' => $left['error'], 'eloquent' => $right['error'] ?? null];
            }
            $steps = [];
            foreach ($left['steps'] ?? [] as $step => $tables) {
                foreach ($tables as $table => $kinds) {
                    $steps[$step][$table] = array_map('count', $kinds);
                }
            }
            $report[$flow] = ['equal' => [] === $differences, 'steps' => $steps, 'differences' => $differences];
        }

        $file = \dirname(self::file('doctrine')).'/report.json';
        file_put_contents($file, json_encode(['doctrine_flushes_during_eloquent' => $eloquent['doctrine_flushes'] ?? null, 'flows' => $report], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)."\n");
        foreach ($report as $flow => $result) {
            self::assertTrue($result['equal'], $flow.': '.json_encode($result['differences'], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
        }
        self::assertSame(0, $eloquent['doctrine_flushes'] ?? null);
    }

    /** @internal the Doctrine listener's count */
    public function doctrineFlushed(): void
    {
        ++$this->doctrineFlushes;
    }

    // ------------------------------------------------------------------ the flows

    /** 1. The sign-up: the form, Registration, the verification link followed, the approval, a password reset. */
    private function signUp(): void
    {
        $c = $this->side->container;
        $forms = new \App\Form\Definition\Forms($this->side->repository('App\\Repository\\Destination\\Destinations'));
        $model = new \App\Form\Model\SecurityRegistrationModel();
        $submission = $this->processor()->handle($forms->signUp(), [
            'email' => self::EMAIL,
            'username' => '',
            'firstname' => 'Flore',
            'lastname' => 'Septcent',
            'plainPassword' => ['first' => self::PASSWORD, 'second' => self::PASSWORD],
            'agreeTerms' => '1',
        ], $model);
        $this->reads['signUp.violations'] = array_map(static fn ($v): string => $v->path.': '.$v->message, $submission->violations);
        self::assertTrue($submission->isValid(), 'the sign-up form holds: '.json_encode($this->reads['signUp.violations']));

        $registration = new Registration($c->get(ParameterBagInterface::class), $this->side->unitOfWork, $this->side->repository(Users::class, 'App\\Entity\\User'), $c->get(NotifierInterface::class));
        $user = $registration->register($this->hydrate(new \App\Entity\User(), $submission), $submission->data->plainPassword);
        $this->reads['signUp.username'] = $user->getUsername();
        $this->step('registered');
        $this->access->user = $user;

        // The verification link asked (the profile's button), then followed.
        $verification = new Verification($this->access, $this->side->unitOfWork, $this->side->repository(Tokens::class), $c->get(NotifierInterface::class));
        $verification->request();
        $this->step('verification asked');
        $token = $user->getValidToken(Verification::TOKEN);
        self::assertNotNull($token, 'a verification token');
        $verification->confirm($verification->tokenOwner($token->get()));
        $this->reads['signUp.verified'] = $user->isVerified();
        $this->step('verified');

        // Approved by an administrator when the site does not approve by itself (omnibase/admin's edit: a flush).
        if (!$user->isApproved()) {
            $user->approve();
            $this->side->unitOfWork->flush();
        }
        $this->reads['signUp.approved'] = $user->isApproved();
        $this->step('approved');

        // A password forgotten: the link asked, followed, a new password chosen.
        $passwords = new Passwords($this->access, $this->side->unitOfWork, $this->side->repository(Users::class, 'App\\Entity\\User'), $this->side->repository(Tokens::class), $c->get(SecurityPolicy::class), $c->get(NotifierInterface::class));
        $this->access->user = null;
        $passwords->resetRequested(self::EMAIL);
        $this->step('reset asked');
        $reset = $user->getValidToken(Passwords::RESET_TOKEN);
        self::assertNotNull($reset, 'a reset token');
        $found = $passwords->resetToken($reset->get());
        self::assertInstanceOf(\Base\Entity\User\Token::class, $found);
        $passwords->reset($found, self::NEW_PASSWORD);
        $this->step('password reset');
        $this->reads['signUp.tokens'] = array_map(static fn ($t): array => [$t->getName(), $t->isValid()], $user->getTokens()->toArray());
    }

    /** 2. Signing in: a wrong password, the right one, then a sign-in link from another browser; presence each time. */
    private function signIn(): void
    {
        $c = $this->side->container;
        $user = $this->side->repository(Users::class, 'App\\Entity\\User')->findOneBy(['email' => self::EMAIL]);
        self::assertNotNull($user, 'the account signed up');
        $uow = $this->side->unitOfWork;

        $accounts = new UserAccounts($uow);
        $sender = new CapturingSender();
        $connections = $this->side->repository(Connections::class);
        $tracker = new UserTracker($uow, $this->requests, $c->get(AdvancedRouterInterface::class), $connections);
        $state = new ArrayState();
        $events = new EventDispatcher();
        $track = new TrackConnections($tracker, $uow, $accounts);
        $events->addListener(SignedIn::class, [$track, 'onSignedIn'], 16);
        $events->addListener(SignInFailed::class, [$track, 'onSignInFailed']);
        $events->addListener(SignedIn::class, [new WarnNewDevice($c->get(SecurityPolicy::class), $tracker, $connections, $state, static fn (): bool => false), 'onSignedIn'], 8);
        $events->addListener(SignedIn::class, [new UpdateAccount($uow), 'onSignedIn']);
        $omniauth = new Omniauth($accounts, new Methods([new PasswordMethod($accounts, new UserPasswords($uow)), new LinkMethod($accounts, new UserLinkTokens($uow), $sender)]), $state, null, null, $events);
        $presence = new Presence($uow);

        $context = new Context('https://lesringostars.localhost', 'lesringostars.localhost', '203.0.113.71', self::AGENT);
        $wrong = $omniauth->verify(Factor::Password, $context, ['identifier' => self::EMAIL, 'password' => 'not-the-password']);
        $this->step('wrong password');
        $right = $omniauth->verify(Factor::Password, $context, ['identifier' => self::EMAIL, 'password' => self::NEW_PASSWORD]);
        $this->reads['signIn.password'] = [$wrong->status->name, $right->status->name];
        $this->step('signed in');
        $presence->poke($user);
        $this->step('presence');

        // Another browser, by a link.
        $this->browser('f7c3-browser-2', '203.0.113.72');
        $omniauth->abandon();
        $omniauth->begin(Factor::Link, $context, ['identifier' => self::EMAIL]);
        self::assertCount(1, $sender->messages, 'the link sent');
        $this->step('link sent');
        $link = $omniauth->verify(Factor::Link, new Context('https://lesringostars.localhost', 'lesringostars.localhost', '203.0.113.72', self::AGENT), ['token' => $sender->messages[0]->secret]);
        $this->reads['signIn.link'] = $link->status->name;
        $this->step('link followed');
        // Away since New Year, then back: the presence written again.
        $user->poke(new \DateTime('2026-01-01 00:00:00', new \DateTimeZone('UTC')));
        $uow->flush();
        $this->step('away');
        $presence->poke($user);
        $this->step('presence again');
        $this->reads['signIn.connections'] = $connections->countFor($user);
        $this->browser('f7c3-browser-1', '203.0.113.71');
    }

    /** 3. A comment under an article, then a reply to it, as their forms make them (CommentPosting). */
    private function comment(): void
    {
        $c = $this->side->container;
        $author = $this->side->repository(Users::class, 'App\\Entity\\User')->findOneBy(['email' => self::EMAIL]);
        $article = $this->side->find('App\\Entity\\Article\\Article', $this->firstPublishedArticle());
        self::assertNotNull($article, 'an article to comment on');
        $forms = new \App\Form\Definition\Forms($this->side->repository('App\\Repository\\Destination\\Destinations'));
        $posting = new \App\Form\Submission\CommentPosting($c->get(SpamCheckerInterface::class), $c->get(Translations::class), $this->side->unitOfWork);

        $submission = $this->processor()->handle($forms->comment(), ['content' => '<p>Un commentaire des flux, F7c.</p>', 'submit' => ''], new \App\Form\Model\ArticleCommentModel());
        self::assertTrue($submission->isValid(), 'the comment\'s form holds');
        $comment = $this->hydrate(new \App\Entity\Article\Comment($author), $submission);
        $posting->prepare($comment, $article, $author);
        $this->reads['comment.refused'] = $posting->keepComment($comment, $article, $author);
        $this->step('comment');

        $reply = $this->processor()->handle($forms->reply(), ['content' => '<p>Une réponse des flux.</p>', 'comment_uuid' => (string) $comment->getUuid(), 'submit' => ''], new \App\Form\Model\ArticleCommentReplyModel());
        self::assertTrue($reply->isValid(), 'the reply\'s form holds');
        $record = $this->hydrate(new \App\Entity\Article\CommentReply($author), $reply);
        $record->setState(\Base\Enum\ThreadState::PUBLISH);
        $this->reads['reply.refused'] = $posting->keepReply($record, $author);
        $this->reads['reply.comment'] = null !== $record->getComment();
    }

    /** 4. The newsletter's form, every destination, then the same address again with one destination. */
    private function newsletter(): void
    {
        $forms = new \App\Form\Definition\Forms($destinations = $this->side->repository('App\\Repository\\Destination\\Destinations'));
        $subscription = new \App\Form\Submission\NewsletterSubscription($this->side->repository('App\\Repository\\Newsletter\\Subscribers'));

        $sent = $this->processor()->handle($forms->newsletter(), ['email' => 'f7c3.newsletter@example.org', 'all' => '1'], new \App\Form\Model\NewsletterSubscriberModel());
        self::assertTrue($sent->isValid(), 'the newsletter\'s form holds: '.json_encode(array_map(static fn ($v): string => $v->path.': '.$v->message, $sent->violations)));
        $subscriber = $subscription->accept($subscription->record($sent->data), null);
        $this->step('subscribed');
        $this->reads['newsletter.destinations'] = array_map(static fn ($d): int => $d->getId(), array_values(iterator_to_array($subscriber->getDestinations())));

        $one = $destinations->subscribable()[0] ?? null;
        self::assertNotNull($one, 'a destination to pick');
        $again = $this->processor()->handle($forms->newsletter(), ['email' => 'f7c3.newsletter@example.org', 'destinations' => [(string) $one->getId()]], new \App\Form\Model\NewsletterSubscriberModel());
        self::assertTrue($again->isValid(), 'the second form holds');
        $subscriber = $subscription->accept($subscription->record($again->data), null);
        $this->reads['newsletter.again'] = array_map(static fn ($d): int => $d->getId(), array_values(iterator_to_array($subscriber->getDestinations())));
    }

    /** 5. Trash: the newsletter left (into the trash), then subscribed again (restored); a vaulted setting written and read. */
    private function trashAndVault(): void
    {
        $c = $this->side->container;
        $subscribers = $this->side->repository('App\\Repository\\Newsletter\\Subscribers');
        $obfuscator = $c->get(ObfuscatorInterface::class);
        $hashid = $obfuscator->encode(['email' => 'f7c3.newsletter@example.org']);

        (new \App\Action\Newsletter\Unsubscribe($obfuscator, $subscribers))($hashid);
        $this->step('trashed');
        $this->side->clear();
        $this->reads['trash.listed'] = null !== $subscribers->oneByEmail('f7c3.newsletter@example.org');
        $this->reads['trash.trashed'] = null !== $subscribers->findOneByEmailIncludingTrashed('f7c3.newsletter@example.org');

        $this->side->clear();
        (new \App\Action\Newsletter\Subscribe($obfuscator, $subscribers))($hashid);
        $this->step('restored');
        $this->side->clear();
        $this->reads['trash.restored'] = null !== $subscribers->oneByEmail('f7c3.newsletter@example.org');

        $settings = $this->settingBag();
        $settings->secure('f7c3.flows.secret');
        $this->step('secured');
        $settings->set('f7c3.flows.secret', 'un secret des flux');
        $this->side->clear();
        $this->reads['vault.read'] = $this->settingBag()->getScalar('f7c3.flows.secret');
    }

    /**
     * 6. An article's tags: one added, one removed, the rest reordered (a many-to-many with an OrderColumn); an
     * account's links (the profile's: a many-to-many with an OrderColumn, cascaded, orphans removed) added, reordered,
     * one removed; a gallery's photos reordered (an inverse one-to-many with an OrderColumn).
     */
    private function tagsAndOrder(): void
    {
        $id = (int) $this->doctrineConnection()->fetchOne('SELECT thread_id FROM thread_tags GROUP BY thread_id HAVING COUNT(*) >= 3 ORDER BY thread_id LIMIT 1');
        $article = $this->side->find('App\\Entity\\Article\\Article', $id);
        self::assertNotNull($article, 'an article with tags');
        $tags = array_values($article->getTags()->toArray());
        $kept = array_map(static fn ($t): int => $t->getId(), $tags);
        $other = (int) $this->doctrineConnection()->fetchOne('SELECT MIN(id) FROM threadTag WHERE id NOT IN ('.implode(',', $kept).')');
        $article->removeTag($tags[1]);
        $article->removeTag($tags[0]);
        $article->addTag($tags[0]);
        $article->addTag($this->side->find('App\\Entity\\Thread\\Tag', $other));
        $this->side->unitOfWork->flush();
        $this->step('tags');
        $this->reads['tags'] = array_map(static fn ($t): int => $t->getId(), array_values($article->getTags()->toArray()));

        $user = $this->side->repository(Users::class, 'App\\Entity\\User')->findOneBy(['email' => self::EMAIL]);
        $adapter = new \Base\Entity\Layout\Attribute\Adapter\HyperpatternAdapter('F7c', 'f7c3-flows', 'fa-solid fa-link', 'https://example.org/{0}');
        $adapter->setPlaceholder(['flore']);
        $this->side->unitOfWork->persist($adapter);
        $this->side->unitOfWork->flush();
        $this->step('link pattern');
        $class = 'Base\\Entity\\Layout\\Attribute\\Hyperlink';
        [$a, $b, $d] = [new $class($adapter, 'flore-a'), new $class($adapter, 'flore-b'), new $class($adapter, 'flore-c')];
        $user->addHyperlink($a)->addHyperlink($b)->addHyperlink($d);
        $this->side->unitOfWork->flush();
        $this->step('links added');
        $user->removeHyperlink($a);
        $user->addHyperlink($a);
        $this->side->unitOfWork->flush();
        $this->step('links reordered');
        $user->removeHyperlink($b);
        $this->side->unitOfWork->flush();
        $this->step('link removed');
        $this->side->clear();
        $user = $this->side->repository(Users::class, 'App\\Entity\\User')->findOneBy(['email' => self::EMAIL]);
        $this->reads['links'] = array_map(static fn ($h): array => (array) $h->getValue(), array_values($user->getHyperlinks()->toArray()));

        $gallery = (int) $this->doctrineConnection()->fetchOne('SELECT MIN(id) FROM gallery');
        $gallery = $this->side->find('App\\Entity\\Gallery\\Gallery', $gallery);
        self::assertNotNull($gallery, 'a gallery');
        $photos = $gallery->getPhotos();
        $first = $photos->first();
        $photos->removeElement($first);
        $photos->add($first);
        $this->side->unitOfWork->flush();
        $this->step('photos reordered');
        $this->side->clear();
        $gallery = $this->side->find('App\\Entity\\Gallery\\Gallery', $gallery->getId());
        $this->reads['photos'] = array_map(static fn ($p): int => $p->getId(), array_values($gallery->getPhotos()->toArray()));
    }

    /** 7. A setting written, its label too, then changed. */
    private function settings(): void
    {
        $settings = $this->settingBag();
        $settings->set('f7c3.flows.plain', 'une valeur');
        $this->step('set');
        $settings->setLabel('f7c3.flows.plain', 'Un réglage des flux');
        $this->step('labelled');
        $settings->set('f7c3.flows.plain', 'une autre valeur');
        $this->side->clear();
        $this->reads['settings.read'] = $this->settingBag()->getScalar('f7c3.flows.plain');
    }

    // ------------------------------------------------------------------ helpers

    /** What the flow wrote since its last step, kept under that step's name. */
    private function step(string $name): void
    {
        $now = $this->rows->snapshot();
        $this->steps[$name] = $this->rows->changes($this->previous, $now, $this->start);
        $this->previous = $now;
    }

    private function processor(): FormProcessor
    {
        $c = $this->side->container;

        return new FormProcessor($c->get(Validation::class), null, $this->side->unitOfWork, null, null, $c->get(Translations::class));
    }

    /** A record written from a valid form's model, its mapped fields only (the twin's AccountPages/CommentForms::hydrate()). */
    private function hydrate(object $record, FormSubmission $submission): object
    {
        $virtual = array_keys(array_filter($submission->form->getFields(), static fn ($field): bool => (bool) $field->isVirtual()));

        return (new EntityHydrator($this->side->unitOfWork))->hydrate($record, $submission->data, $virtual, EntityHydrator::CLASS_METHODS | EntityHydrator::FETCH_ASSOCIATIONS);
    }

    private function settingBag(): SettingBag
    {
        $c = $this->side->container;

        return new SettingBag($c->get(ParameterBagInterface::class), $this->side->unitOfWork, $this->side->repository(Settings::class), $c->get(LocalizerInterface::class), new ArrayAdapter(), 'test');
    }

    private function firstPublishedArticle(): int
    {
        $metadata = static::getContainer()->get('doctrine')->getManager()->getClassMetadata('App\\Entity\\Article\\Article');

        return (int) $this->doctrineConnection()->fetchOne('SELECT MIN(a.id) FROM `'.$metadata->getTableName().'` a JOIN thread t ON t.id = a.id WHERE t.state = \'STATE_PUBLISH\' AND t.deletedAt IS NULL');
    }

    private function doctrineConnection(): \Doctrine\DBAL\Connection
    {
        return static::getContainer()->get('doctrine')->getManager()->getConnection();
    }

    /** The browser the flow runs in: its PHPUSERID in its session, its address and agent in PHP's globals. */
    private function browser(string $uniqid, string $ip): void
    {
        foreach (['REMOTE_ADDR', 'HTTP_USER_AGENT'] as $key) {
            $this->server[$key] ??= (string) ($_SERVER[$key] ?? '');
        }
        $_SERVER['REMOTE_ADDR'] = $ip;
        $_SERVER['HTTP_USER_AGENT'] = self::AGENT;
        while (null !== $this->requests->pop()) {
        }
        $request = Request::create('https://lesringostars.localhost/', 'GET', [], [], [], ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => self::AGENT]);
        $session = new Session(new MockArraySessionStorage());
        $session->set(UserTracker::PHPUSERID, $uniqid);
        $request->setSession($session);
        $this->requests->push($request);

        // The container's request stack too: the notifier's flashes and the services it hands out read it.
        $stack = static::getContainer()->get('request_stack');
        while (null !== $stack->pop()) {
        }
        $stack->push($request);
    }

    /**
     * Two lists of rows side by side, each pair of rows reduced to the columns that differ.
     *
     * @return array<string, mixed>
     */
    private static function columns(array $a, array $b): array
    {
        $out = ['doctrine' => \count($a), 'eloquent' => \count($b)];
        for ($i = 0, $n = max(\count($a), \count($b)); $i < $n; ++$i) {
            $left = $a[$i] ?? null;
            $right = $b[$i] ?? null;
            if ($left === $right) {
                continue;
            }
            if (!\is_array($left) || !\is_array($right)) {
                $out['rows'][$i] = ['doctrine' => $left, 'eloquent' => $right];
                continue;
            }
            foreach (array_unique([...array_keys($left), ...array_keys($right)]) as $column) {
                if (($left[$column] ?? null) !== ($right[$column] ?? null)) {
                    $out['rows'][$i][$column] = ['doctrine' => $left[$column] ?? null, 'eloquent' => $right[$column] ?? null];
                }
            }
        }

        return $out;
    }

    private static function file(string $backend): string
    {
        return static::getContainer()->getParameter('kernel.project_dir').'/var/eloquent-flows/'.$backend.'.json';
    }
}
