<?php

namespace Omnibridge\Eloquent\Tests\Harness;

require_once \dirname(__DIR__).'/Site/autoload.php';

use Doctrine\ORM\EntityManagerInterface;
use Omnibridge\Eloquent\Tests\Support\AnalyticsParity;
use Omnibridge\Eloquent\Tests\Support\FinderParity;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The core's analytics and Redirections::hit() (written once in the core's language) on both backends in
 * omnisense/omnibase's harness (SQLite): the rows the harness has read alike, the writes give the same rows
 * (AnalyticsParity, rolled back) - one code for both engines, no known difference left.
 *
 *     docker compose -f compose.yml -f compose.checkouts.yml -f compose.eloquent.yml run --rm omnibase \
 *         test --filter AnalyticsParityTest /srv/omnibridge/eloquent/tests/Harness/AnalyticsParityTest.php
 *
 * The report goes to var/eloquent-analytics-harness.json.
 */
final class AnalyticsParityTest extends KernelTestCase
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
        $result = AnalyticsParity::run($entityManager, $persistence, static::getContainer()->getParameter('kernel.project_dir').'/var/eloquent-analytics-harness.json', []);

        self::assertSame([], FinderParity::unequal($result['reads']));
        foreach ($result['reads'] as $name => $entry) {
            self::assertSame(FinderParity::EQUAL, $entry['outcome'], $name);
        }
        self::assertSame($result['writes']['doctrine'], $result['writes']['eloquent']);
        self::assertSame('2', $result['writes']['doctrine']['the redirection'][0]['hits']);
    }
}
