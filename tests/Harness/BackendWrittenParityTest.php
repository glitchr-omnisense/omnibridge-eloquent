<?php

namespace Omnibridge\Eloquent\Tests\Harness;

require_once \dirname(__DIR__).'/Site/autoload.php';

use Doctrine\ORM\EntityManagerInterface;
use Omnibridge\Eloquent\Tests\Support\BackendWrittenParity;
use Omnibridge\Eloquent\Tests\Support\FinderParity;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The 14 methods no finder compiles (#[BackendOnly], plan "omnibase agnostic", step F7f) on both backends in
 * omnisense/omnibase's harness: SQLite's statements (ON CONFLICT, INSERT ... ON CONFLICT DO NOTHING). The rows the
 * harness has read alike, the writes give the same rows (BackendWrittenParity, rolled back). Visits::returning() is a
 * known difference here: omnibridge/doctrine aliases a column `returning`, a keyword of SQLite since 3.35, so its
 * statement fails on SQLite (MySQL accepts it); omnibridge/eloquent quotes the alias.
 *
 *     docker compose -f compose.yml -f compose.checkouts.yml -f compose.eloquent.yml run --rm omnibase \
 *         test --filter BackendWrittenParityTest /srv/omnibridge/eloquent/tests/Harness/BackendWrittenParityTest.php
 *
 * The report goes to var/eloquent-backend-written-harness.json.
 */
final class BackendWrittenParityTest extends KernelTestCase
{
    public function testTheMethodsEachPersistenceWritesGiveTheSameRows(): void
    {
        if (!getenv('HARNESS_SUITE_BOOTSTRAP')) {
            self::markTestSkipped('Run in omnisense/omnibase\'s harness.');
        }
        self::bootKernel();
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get('doctrine')->getManager();
        $persistence = FinderParity::persistence($entityManager, (string) static::getContainer()->getParameter('kernel.project_dir'));
        $sqlite = str_contains(strtolower($entityManager->getConnection()->getDatabasePlatform()::class), 'sqlite');

        $result = BackendWrittenParity::run($entityManager, $persistence, static::getContainer()->getParameter('kernel.project_dir').'/var/eloquent-backend-written-harness.json', $sqlite ? [
            'Visits::returning' => 'omnibridge/doctrine\'s alias `returning` is a keyword of SQLite since 3.35: its statement fails there, Eloquent quotes it',
        ] : []);

        self::assertSame([], FinderParity::unequal($result['reads']));
        foreach ($result['reads'] as $name => $entry) {
            if (FinderParity::KNOWN === $entry['outcome']) {
                self::assertStringStartsWith('Visits::returning', $name);
                self::assertArrayHasKey('exception', $entry['doctrine'], $name);
            } else {
                self::assertSame(FinderParity::EQUAL, $entry['outcome'], $name);
            }
        }
        self::assertSame($result['writes']['doctrine'], $result['writes']['eloquent']);
        self::assertSame('2', $result['writes']['doctrine']['the redirection'][0]['hits']);
    }
}
