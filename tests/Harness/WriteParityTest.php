<?php

namespace Omnibridge\Eloquent\Tests\Harness;

require_once \dirname(__DIR__).'/Site/autoload.php';

use Base\Database\UnitOfWorks;
use Base\Entity\Layout\Setting;
use Base\Entity\Layout\ShortLink;
use Base\Entity\Layout\Widget\Page;
use Base\Entity\Thread;
use Base\Entity\Thread\Taxon;
use Doctrine\ORM\EntityManagerInterface;
use Omnibridge\Eloquent\Omnibase\EloquentPersistence;
use Omnibridge\Eloquent\Tests\Support\OmnibaseHarness;
use Omnibridge\Persistence\UnitOfWork;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Marshaller\SodiumMarshaller;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The writes of omnibase's behaviours on both backends (plan "omnibase agnostic", step F7c, second part): the package's
 * WriteParityTest (tests/, on its fixtures) for the core's entities, in omnisense/omnibase's harness (SQLite). Each
 * scenario runs through Doctrine's unit of work in a transaction - the rows of its tables read, the transaction rolled
 * back - then through omnibridge/eloquent's, with omnibase's lifecycle attached as a host attaches it (OmnibaseHarness:
 * the behaviours' relay, the trash, the revisions, the translations, the threads' participants, the settings, the
 * entity dispatchers), in a transaction on the same database, rolled back too. Every column of every table the
 * scenario touches must be equal: the identifiers as the databases gave them (the same, both runs starting from the
 * same rows), the dates relative to the scenario's start (to the hour), what is random by its shape (a UUID, a
 * random string by its length), a password hash by the password it verifies, a sealed value by the plain value it
 * opens to; an uploaded file by its content.
 *
 * Scenarios: Timestamp and Blameable (a trash entry's initiator, the signed-in user), Slugify (a slug, its "-2", per
 * type), Uploader (a page's thumbnail, on a scratch directory emptied after), Vault (a secured setting), Cache (a
 * cached entity written), OrderColumn (a reorder), GenerateUuid and Randomize (a thread's uuid, a short link's
 * random slug), Hashify (a user's password), the trash (a removal turned into a trash entry, then a purge) and the
 * versions (a thread's revision), orphan removal's cascade (an account removed with its connections). Countify is
 * lesringostars' (tests/Site).
 *
 *     docker compose -f compose.yml -f compose.checkouts.yml -f compose.eloquent.yml run --rm omnibase \
 *         test --filter WriteParityTest /srv/omnibridge/eloquent/tests/Harness/WriteParityTest.php
 *
 * The report goes to var/eloquent-write-parity-harness.json.
 */
final class WriteParityTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private ?object $user = null;

    private \DateTimeImmutable $start;

    /** @var array<string, array<string, mixed>> */
    private static array $report = [];

    protected function setUp(): void
    {
        if (!getenv('HARNESS_SUITE_BOOTSTRAP')) {
            self::markTestSkipped('Run in omnisense/omnibase\'s harness.');
        }
        self::bootKernel();
        $this->entityManager = static::getContainer()->get('doctrine')->getManager();
        $_SERVER['REMOTE_ADDR'] ??= '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] ??= 'phpunit';
    }

    protected function tearDown(): void
    {
        if (null !== $this->user) {
            $this->entityManager->clear();
            $user = $this->entityManager->find($this->user::class, $this->user->getId());
            $user && $this->entityManager->remove($user);
            $this->entityManager->flush();
            $this->user = null;
        }
        static::getContainer()->get('security.token_storage')->setToken(null);
        // The Eloquent side's entity dispatchers let go (they are live ones: AbstractEventDispatcher::live()).
        gc_collect_cycles();
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        if ([] !== self::$report) {
            @file_put_contents(getcwd().'/var/eloquent-write-parity-harness.json', json_encode(self::$report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR)."\n");
        }
        parent::tearDownAfterClass();
    }

    public function testTimestampBlameableAndTheTrash(): void
    {
        $this->signedIn();
        $this->compare('timestamp, blameable, trash', function (UnitOfWork $unitOfWork, \Closure $find): void {
            $thread = new Thread(null, null, 'À la corbeille', 'a-la-corbeille');
            $unitOfWork->persist($thread);
            $unitOfWork->flush();
            $unitOfWork->remove($thread);
            $unitOfWork->flush();
            // Removed again: the entry stays, the thread is deleted for good.
            $unitOfWork->remove($thread);
            $unitOfWork->flush();
        }, [Thread::class, \Base\Entity\Extension\TrashBall::class]);
    }

    public function testSlugifyGenerateUuidAndTheSlugsSuffix(): void
    {
        $this->compare('slugify, generate uuid', function (UnitOfWork $unitOfWork): void {
            foreach (['Même titre', 'Même titre'] as $title) {
                $thread = new Thread(null, null, $title);
                $unitOfWork->persist($thread);
                $unitOfWork->flush();
            }
            // Two in one flush: the second sees the first's slug among the pending writes.
            $unitOfWork->persist(new Thread(null, null, 'Deux à la fois'));
            $unitOfWork->persist(new Thread(null, null, 'Deux à la fois'));
            $unitOfWork->flush();
            $unitOfWork->persist(new Taxon('Desserts'));
            $unitOfWork->persist(new Taxon('Desserts'));
            $unitOfWork->flush();
        }, [Thread::class, Taxon::class]);
    }

    public function testRandomizeSlugifyAndCache(): void
    {
        $this->compare('randomize, cache', function (UnitOfWork $unitOfWork): void {
            $link = new ShortLink('https://example.org/une/adresse', 'Une adresse');
            $unitOfWork->persist($link);
            $unitOfWork->flush();
            $link->setUrl('https://example.org/une/autre');
            $unitOfWork->flush();
        }, [ShortLink::class], ['random' => ['slug']]);
    }

    public function testOrderColumnAndTheVersions(): void
    {
        $this->compare('order column, versions', function (UnitOfWork $unitOfWork, \Closure $find, \Closure $clear): void {
            $taxa = [new Taxon('Un'), new Taxon('Deux'), new Taxon('Trois')];
            $thread = new Thread(null, null, 'Ordonné');
            foreach ($taxa as $taxon) {
                $unitOfWork->persist($taxon);
                $thread->addTaxon($taxon);
            }
            $unitOfWork->persist($thread);
            $unitOfWork->flush();
            $id = $thread->getId();

            $clear();
            $thread = $find(Thread::class, $id);
            $byLabel = [];
            foreach ($thread->getTaxa() as $taxon) {
                $byLabel[$taxon->getLabel()] = $taxon;
            }
            $thread->getTaxa()->clear();
            foreach (['Trois', 'Un', 'Deux'] as $label) {
                $thread->addTaxon($byLabel[$label]);
            }
            $thread->setTitle('Ordonné, renommé');
            $unitOfWork->flush();
            $thread->setContent('Un contenu, une révision.');
            $unitOfWork->flush();
        }, [Thread::class, Taxon::class, \Base\Entity\ThreadIntl::class, \Base\Entity\Extension\Revision::class], ['random' => ['hash']]);
    }

    public function testHashify(): void
    {
        $this->compare('hashify, timestamp', function (UnitOfWork $unitOfWork): void {
            $class = 'App\\Entity\\User';
            $user = new $class();
            if (method_exists($user, 'setUsername')) {
                $user->setUsername('parity');
            }
            $user->setEmail('parity@example.org');
            $user->setPlainPassword('a parity password');
            $unitOfWork->persist($user);
            $unitOfWork->flush();
            $user->setPlainPassword('another parity password');
            $user->setEmail('parity2@example.org');
            $unitOfWork->flush();
        }, ['App\\Entity\\User'], ['passwords' => ['a parity password', 'another parity password']]);
    }

    /**
     * Orphan removal implies the removal's cascade, as Doctrine completes the mapping: an account removed takes its
     * browsers' connections (User::$connections: orphanRemoval, no cascade declared) - the collection never read,
     * read for the removal. On Eloquent the userConnection rows stayed, and on MySQL the account's delete failed on
     * their foreign key (the core's demo and sign-in tests in a site on eloquent, step F7f).
     */
    public function testAnAccountRemovedTakesItsConnections(): void
    {
        $this->compare('orphan removal, the removal\'s cascade', function (UnitOfWork $unitOfWork, \Closure $find, \Closure $clear): void {
            $class = 'App\\Entity\\User';
            $users = [];
            foreach (['kept', 'gone'] as $name) {
                $user = new $class();
                if (method_exists($user, 'setUsername')) {
                    $user->setUsername('parity-'.$name);
                }
                $user->setEmail('parity-'.$name.'@example.org');
                $user->setPlainPassword('a parity password');
                $unitOfWork->persist($user);
                $unitOfWork->persist(new \Base\Entity\User\Connection('parity-'.$name, $user));
                $users[$name] = $user;
            }
            $unitOfWork->flush();
            $clear();
            $unitOfWork->remove($find($class, $users['gone']->getId()));
            $unitOfWork->flush();
        }, ['App\\Entity\\User', \Base\Entity\User\Connection::class], ['passwords' => ['a parity password']]);
    }

    public function testVault(): void
    {
        if (!\function_exists('sodium_crypto_box_keypair')) {
            self::markTestSkipped('Requires ext-sodium.');
        }
        $keypair = sodium_crypto_box_keypair();
        $previous = $_SERVER['SYMFONY_DECRYPTION_SECRET'] ?? null;
        $_SERVER['SYMFONY_DECRYPTION_SECRET'] = base64_encode($keypair);
        try {
            $this->compare('vault', function (UnitOfWork $unitOfWork): void {
                $setting = new Setting('base.parity.secret', 'sk_parity_1', 'en-GB');
                foreach ($setting->getTranslations() as $translation) {
                    $translation->setSecure(true);
                }
                $unitOfWork->persist($setting);
                $unitOfWork->flush();
                foreach ($setting->getTranslations() as $translation) {
                    $translation->setValue('sk_parity_2');
                }
                $unitOfWork->flush();
            }, [Setting::class, \Base\Entity\Layout\SettingIntl::class], ['keypair' => $keypair]);
        } finally {
            null === $previous ? (function () { unset($_SERVER['SYMFONY_DECRYPTION_SECRET']); })() : $_SERVER['SYMFONY_DECRYPTION_SECRET'] = $previous;
        }
    }

    /** The passkeys' records (webauthn's credential repository): saved, saved again, found, on both. */
    public function testPasskeyRecords(): void
    {
        if (!class_exists(\Webauthn\CredentialRecord::class)) {
            self::markTestSkipped('Requires web-auth/webauthn-lib.');
        }
        $this->signedIn();
        $userHandle = (string) $this->user->getId();
        $record = static fn (int $counter) => \Webauthn\CredentialRecord::create("\x01\x02credential", 'public-key', ['internal'], 'none', new \Webauthn\TrustPath\EmptyTrustPath(), \Symfony\Component\Uid\Uuid::fromString('00000000-0000-0000-0000-000000000000'), "\x03\x04key", $userHandle, $counter);
        $this->compare('passkey records', function (UnitOfWork $unitOfWork) use ($record): void {
            $passkeys = UnitOfWorks::repositories($unitOfWork)->of(\Base\Entity\User\Passkey::class);
            self::assertInstanceOf(\Base\Contract\PasskeyRecords::class, $passkeys);
            $passkeys->saveCredentialRecord($record(1));
            $passkeys->saveCredentialRecord($record(7));
            self::assertSame(7, $passkeys->findOneByCredentialId("\x01\x02credential")?->counter);
            self::assertCount(1, $passkeys->findAllForUserEntity(\Webauthn\PublicKeyCredentialUserEntity::create('user', $record(0)->userHandle, 'User')));
        }, [\Base\Entity\User\Passkey::class]);
    }

    public function testUploader(): void
    {
        $directory = static::getContainer()->getParameter('kernel.project_dir').'/var/storage/uploads';
        $before = self::files($directory);
        $png = tempnam(sys_get_temp_dir(), 'parity');
        $contents = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $written = [];
        try {
            $this->compare('uploader', function (UnitOfWork $unitOfWork) use ($png, $contents): void {
                file_put_contents($png, $contents);
                $page = new Page('Une page', 'une-page');
                $page->setThumbnail(new \SplFileInfo($png));
                $unitOfWork->persist($page);
                $unitOfWork->flush();
            }, [Page::class], ['files' => function () use ($directory, $before, &$written): array {
                // The files this side wrote, by content (their names are UUIDs); removed: a scratch directory.
                $new = array_diff(self::files($directory), $before);
                $contents = [];
                foreach ($new as $file) {
                    $contents[] = md5((string) file_get_contents($file));
                    @unlink($file);
                    $written[] = $file;
                }
                sort($contents);

                return $contents;
            }]);
        } finally {
            @unlink($png);
            foreach (array_diff(self::files($directory), $before) as $file) {
                @unlink($file);
            }
        }
        self::assertNotEmpty($written, 'a file was written on each side');
    }

    /**
     * The scenario on both backends, its tables' rows compared.
     *
     * @param list<class-string>   $classes the entities whose tables (and join tables) the scenario writes
     * @param array<string, mixed> $options passwords, keypair, files
     */
    private function compare(string $name, \Closure $scenario, array $classes, array $options = []): void
    {
        $tables = $this->tables($classes);
        $this->start = new \DateTimeImmutable();

        $doctrine = $this->onDoctrine($scenario, $tables, $options);
        $eloquent = $this->onEloquent($scenario, $tables, $options);

        $equal = $doctrine === $eloquent;
        self::$report[$name] = ['tables' => $tables, 'equal' => $equal] + ($equal ? ['rows' => $doctrine] : ['doctrine' => $doctrine, 'eloquent' => $eloquent]);
        self::assertSame($doctrine, $eloquent, $name);
        self::assertNotSame([], array_filter($doctrine['rows'] ?? []), $name.': the scenario wrote rows');
    }

    private function onDoctrine(\Closure $scenario, array $tables, array $options): array
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $this->signIn(fn (string $class, mixed $id) => $this->entityManager->find($class, $id));
            $scenario(UnitOfWorks::of($this->entityManager), fn (string $class, mixed $id) => $this->entityManager->find($class, $id), fn () => $this->entityManager->clear());

            return $this->snapshot($tables, $options);
        } finally {
            $connection->rollBack();
            $this->entityManager->clear();
        }
    }

    private function onEloquent(\Closure $scenario, array $tables, array $options): array
    {
        /** @var EloquentPersistence $persistence */
        $persistence = OmnibaseHarness::persistence(static::getContainer());
        $connection = $persistence->store()->connection();
        $connection->beginTransaction();
        try {
            $this->signIn(fn (string $class, mixed $id) => $persistence->store()->find($class, $id));
            $scenario($persistence->unitOfWork(), fn (string $class, mixed $id) => $persistence->store()->find($class, $id), fn () => $persistence->store()->clear());

            return $this->snapshot($tables, $options);
        } finally {
            $connection->rollBack();
        }
    }

    /** A user written for the scenarios that need one signed in (Blameable), committed, removed after the test. */
    private function signedIn(): void
    {
        $class = 'App\\Entity\\User';
        $user = new $class();
        if (method_exists($user, 'setUsername')) {
            $user->setUsername('parity'.bin2hex(random_bytes(3)));
        }
        $user->setEmail('parity'.bin2hex(random_bytes(3)).'@example.org');
        $user->setPlainPassword('a parity password');
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $this->user = $user;
    }

    /** The test's user signed in, as the side reads it (its own object: Blameable writes it as an association). */
    private function signIn(\Closure $find): void
    {
        $storage = static::getContainer()->get('security.token_storage');
        if (null === $this->user) {
            $storage->setToken(null);

            return;
        }
        $user = $find($this->user::class, $this->user->getId());
        $storage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    /** @return array{rows: array<string, list<array<string, mixed>>>, files?: list<string>} */
    private function snapshot(array $tables, array $options): array
    {
        $pdo = $this->entityManager->getConnection()->getNativeConnection();
        $rows = [];
        foreach ($tables as $table) {
            $statement = $pdo->query('SELECT * FROM "'.$table.'"');
            $list = array_map(fn (array $row): array => $this->normalise($row, $options), $statement->fetchAll(\PDO::FETCH_ASSOC));
            usort($list, static fn (array $a, array $b): int => strcmp(json_encode($a), json_encode($b)));
            $rows[$table] = $list;
        }
        $snapshot = ['rows' => $rows];
        if (isset($options['files'])) {
            $snapshot['files'] = $options['files']();
        }

        return $snapshot;
    }

    private function normalise(array $row, array $options): array
    {
        foreach ($row as $column => $value) {
            if (!\is_string($value)) {
                continue;
            }
            // A date inside a text (a trash entry's JSON): its second varies between the two runs.
            $value = $row[$column] = preg_replace('/\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d/', '<date>', $value);
            if (1 === preg_match('/^\d{4}-\d\d-\d\d[ T]\d\d:\d\d:\d\d/', $value)) {
                $hours = (int) round(((new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->getTimestamp() - $this->start->getTimestamp()) / 3600);
                $row[$column] = abs($hours) > 24 * 400 ? $value : \sprintf('<start%+dh>', $hours);
            } elseif (1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $value)) {
                $row[$column] = '<uuid>'.substr($value, 36);
            } elseif (1 === preg_match('/^\$(2y|argon2i|argon2id)\$/', $value)) {
                $verified = array_values(array_filter($options['passwords'] ?? [], static fn (string $password): bool => password_verify($password, $value)));
                $row[$column] = '<hash of '.($verified[0] ?? '?').'>';
            } elseif (isset($options['keypair']) && '' !== $value && null !== ($plain = self::open($options['keypair'], $value))) {
                $row[$column] = '<sealed '.$plain.'>';
            }
        }

        // What #[Randomize] draws: its length.
        foreach ($options['random'] ?? [] as $column) {
            if (\is_string($row[$column] ?? null)) {
                $row[$column] = '<random '.\strlen($row[$column]).'>';
            }
        }

        return $row;
    }

    private static function open(string $keypair, string $value): ?string
    {
        // The column holds JSON (SettingIntl's value): the sealed text, base64, inside.
        $decoded = json_validate($value) ? json_decode($value, true) : $value;
        if (!\is_string($decoded) || false === ($sealed = base64_decode($decoded, true))) {
            return null;
        }
        try {
            $plain = (new SodiumMarshaller([$keypair]))->unmarshall($sealed);

            return \is_string($plain) ? $plain : json_encode($plain);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<string> the classes' tables, their parents' (joined) and their many-to-many join tables */
    private function tables(array $classes): array
    {
        $tables = [];
        foreach ($classes as $class) {
            $metadata = $this->entityManager->getClassMetadata($class);
            foreach ([$metadata->getName(), ...$metadata->parentClasses, ...$metadata->subClasses] as $member) {
                $tables[] = $this->entityManager->getClassMetadata($member)->getTableName();
            }
            foreach ($metadata->associationMappings as $mapping) {
                if ($mapping->isManyToMany() && $mapping->isOwningSide()) {
                    $tables[] = $mapping->joinTable->name;
                }
            }
        }

        return array_values(array_unique($tables));
    }

    /** @return list<string> */
    private static function files(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[] = $file->getPathname();
        }

        return $files;
    }
}
