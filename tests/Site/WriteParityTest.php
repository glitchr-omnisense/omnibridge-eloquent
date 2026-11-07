<?php

namespace Omnibridge\Eloquent\Tests\Site;

require_once __DIR__.'/autoload.php';

use Base\Database\UnitOfWorks;
use Base\Entity\Extension\Revision;
use Doctrine\ORM\EntityManagerInterface;
use Omnibridge\Eloquent\Tests\Support\OmnibaseHarness;
use Omnibridge\Persistence\UnitOfWork;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * omnibase's behaviours writing an application's own entities on both backends (plan "omnibase agnostic", step F7c,
 * second part), on lesringostars' MySQL test database: what tests/Harness/WriteParityTest does for the core's entities,
 * for lesringostars' Article and ArticleIntl - #[Countify] (the words of its content, lesringostars' only use of it),
 * and with it Slugify, GenerateUuid, Timestamp and the revisions. Each side runs in a transaction rolled back; MySQL
 * does not give back the identifiers a rolled-back insert took, so the rows each side wrote (those beyond the highest
 * identifier before) are compared with their identifiers numbered in order, per hierarchy, the foreign keys pointing
 * to them alike, and the dates relative to the scenario's start.
 *
 *     OMNIBRIDGE_ELOQUENT_SITE=1 php bin/phpunit vendor/omnibridge/eloquent/tests/Site/WriteParityTest.php
 */
final class WriteParityTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private \DateTimeImmutable $start;

    protected function setUp(): void
    {
        if (!getenv('OMNIBRIDGE_ELOQUENT_SITE') || !class_exists('App\\Entity\\Article\\Article')) {
            self::markTestSkipped('Run in lesringostars\' suite (OMNIBRIDGE_ELOQUENT_SITE=1).');
        }
        self::bootKernel();
        $this->entityManager = static::getContainer()->get('doctrine')->getManager();
    }

    protected function tearDown(): void
    {
        // The Eloquent side's entity dispatchers let go (they are live ones: AbstractEventDispatcher::live()).
        gc_collect_cycles();
        parent::tearDown();
    }

    public function testAnArticlesWordsAreCountedAlikeOnBothBackends(): void
    {
        $classes = ['App\\Entity\\Article\\Article', 'App\\Entity\\Article\\ArticleIntl', Revision::class];
        [$tables, $spaces] = $this->tables($classes);
        $before = $this->highest($tables);
        $this->start = new \DateTimeImmutable();

        $scenario = static function (UnitOfWork $unitOfWork): void {
            $class = 'App\\Entity\\Article\\Article';
            $article = new $class(null, null, 'Compter les mots, F7c');
            $article->setContent('<p>Un deux trois quatre cinq.</p>');
            $unitOfWork->persist($article);
            $unitOfWork->flush();
            $article->setContent('<p>Un deux.</p>');
            $unitOfWork->flush();
        };

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $scenario(UnitOfWorks::of($this->entityManager));
            $doctrine = $this->rows($tables, $spaces, $before);
        } finally {
            $connection->rollBack();
            $this->entityManager->clear();
        }

        $persistence = OmnibaseHarness::persistence(static::getContainer());
        $eloquentConnection = $persistence->store()->connection();
        $eloquentConnection->beginTransaction();
        try {
            $scenario($persistence->unitOfWork());
            $eloquent = $this->rows($tables, $spaces, $before);
        } finally {
            $eloquentConnection->rollBack();
        }

        @file_put_contents(static::getContainer()->getParameter('kernel.project_dir').'/var/eloquent-write-parity-site.json', json_encode(['tables' => $tables, 'equal' => $doctrine === $eloquent, 'doctrine' => $doctrine, 'eloquent' => $eloquent], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR)."\n");
        self::assertSame($doctrine, $eloquent);
        $words = array_column($doctrine['articleIntl'] ?? [], 'words');
        self::assertSame([2], $words, 'the words counted, as the content says after the update');
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM '.$tables[0].' WHERE id > ?', [$before[$tables[0]]]), 'nothing left after the rollbacks');
    }

    /**
     * The classes' tables (their hierarchies', and their many-to-many join tables), and the identifier space of each
     * column holding one: a table's id is its hierarchy root's, a join column its target root's.
     *
     * @return array{0: list<string>, 1: array<string, array<string, string>>}
     */
    private function tables(array $classes): array
    {
        $tables = [];
        $spaces = [];
        foreach ($classes as $class) {
            $metadata = $this->entityManager->getClassMetadata($class);
            $root = $this->entityManager->getClassMetadata($metadata->rootEntityName)->getTableName();
            foreach ([$metadata->getName(), ...$metadata->parentClasses] as $member) {
                $memberMetadata = $this->entityManager->getClassMetadata($member);
                $table = $memberMetadata->getTableName();
                $tables[] = $table;
                $spaces[$table]['id'] = $root;
                foreach ($memberMetadata->associationMappings as $mapping) {
                    if ($mapping->isToOneOwningSide()) {
                        $target = $this->entityManager->getClassMetadata($mapping->targetEntity);
                        $spaces[$table][$mapping->joinColumns[0]->name] = $this->entityManager->getClassMetadata($target->rootEntityName)->getTableName();
                    }
                }
            }
        }
        // A revision's entityId is its thread's.
        $spaces[$this->entityManager->getClassMetadata('Base\\Entity\\Extension\\Abstract\\AbstractExtension')->getTableName()]['entityId'] = $this->entityManager->getClassMetadata('Base\\Entity\\Thread')->getTableName();

        return [array_values(array_unique($tables)), $spaces];
    }

    /** @return array<string, int> */
    private function highest(array $tables): array
    {
        $highest = [];
        foreach ($tables as $table) {
            $highest[$table] = (int) $this->entityManager->getConnection()->fetchOne('SELECT COALESCE(MAX(id), 0) FROM '.$table);
        }

        return $highest;
    }

    /** @return array<string, list<array<string, mixed>>> each table's new rows, identifiers numbered in order */
    private function rows(array $tables, array $spaces, array $before): array
    {
        $connection = $this->entityManager->getConnection();
        $raw = [];
        $numbers = [];
        foreach ($tables as $table) {
            $raw[$table] = $connection->fetchAllAssociative('SELECT * FROM '.$table.' WHERE id > ? ORDER BY id', [$before[$table]]);
            $root = $spaces[$table]['id'];
            foreach ($raw[$table] as $row) {
                $numbers[$root][(int) $row['id']] ??= '#'.(\count($numbers[$root] ?? []) + 1);
            }
        }

        $rows = [];
        foreach ($raw as $table => $list) {
            foreach ($list as $row) {
                foreach ($row as $column => $value) {
                    $space = $spaces[$table][$column] ?? null;
                    if (null !== $space && null !== $value && isset($numbers[$space][(int) $value])) {
                        $row[$column] = $numbers[$space][(int) $value];
                    } elseif (\is_string($value) && 1 === preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d/', $value)) {
                        $row[$column] = \sprintf('<start%+dh>', (int) round(((new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->getTimestamp() - $this->start->getTimestamp()) / 3600));
                    } elseif (\is_string($value) && 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value)) {
                        $row[$column] = '<uuid>';
                    } elseif ('hash' === $column && \is_string($value)) {
                        $row[$column] = '<random '.\strlen($value).'>';
                    } elseif (\is_string($value)) {
                        $row[$column] = preg_replace('/\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d/', '<date>', $value);
                    }
                }
                $rows[$table][] = $row;
            }
        }

        return $rows;
    }
}
