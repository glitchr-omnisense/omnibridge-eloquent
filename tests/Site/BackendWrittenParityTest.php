<?php

namespace Omnibridge\Eloquent\Tests\Site;

require_once __DIR__.'/autoload.php';

use Base\Entity\Analytics\PageView;
use Doctrine\ORM\EntityManagerInterface;
use Omnibridge\Eloquent\Tests\Support\BackendWrittenParity;
use Omnibridge\Eloquent\Tests\Support\FinderParity;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The 14 methods no finder compiles (#[BackendOnly], plan "omnibase agnostic", step F7f) on both backends, on an
 * application's MySQL database (lesringostars' test database, or a scratch copy of it): the reads on its rows equal,
 * the writes' rows equal (BackendWrittenParity; rolled back). The report goes to var/eloquent-backend-written-site.json.
 *
 *     OMNIBRIDGE_ELOQUENT_SITE=1 php bin/phpunit vendor/omnibridge/eloquent/tests/Site/BackendWrittenParityTest.php
 */
final class BackendWrittenParityTest extends KernelTestCase
{
    public function testTheMethodsEachPersistenceWritesGiveTheSameRows(): void
    {
        if (!getenv('OMNIBRIDGE_ELOQUENT_SITE') || !class_exists('App\\Entity\\Article\\Article')) {
            self::markTestSkipped('Run in lesringostars\' suite (OMNIBRIDGE_ELOQUENT_SITE=1).');
        }
        self::bootKernel();
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get('doctrine')->getManager();
        $persistence = FinderParity::persistence($entityManager, (string) static::getContainer()->getParameter('kernel.project_dir'));
        $table = $entityManager->getClassMetadata(PageView::class)->getTableName();
        $before = (int) $entityManager->getConnection()->fetchOne("SELECT COUNT(*) FROM {$table}");

        $result = BackendWrittenParity::run($entityManager, $persistence, static::getContainer()->getParameter('kernel.project_dir').'/var/eloquent-backend-written-site.json');

        self::assertNotEmpty($result['reads']);
        foreach ($result['reads'] as $name => $entry) {
            self::assertSame(FinderParity::EQUAL, $entry['outcome'], $name);
        }
        self::assertSame($result['writes']['doctrine'], $result['writes']['eloquent']);
        self::assertNotEmpty($result['writes']['doctrine']['page views']);
        self::assertSame([['hits' => '2', 'last_hit_at' => $result['writes']['doctrine']['the redirection'][0]['last_hit_at']]], $result['writes']['doctrine']['the redirection']);
        self::assertSame($before, (int) $entityManager->getConnection()->fetchOne("SELECT COUNT(*) FROM {$table}"), 'nothing left after the rollbacks');
    }
}
