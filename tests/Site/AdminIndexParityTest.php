<?php

namespace Omnibridge\Eloquent\Tests\Site;

require_once __DIR__.'/autoload.php';

use Doctrine\ORM\EntityManagerInterface;
use Omnibridge\Eloquent\Omnibase\EloquentPersistence;
use Omnibridge\Eloquent\Tests\Support\FinderParity;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * omnibase/admin's lists on both compilers (omnisense/omnibase's plan "omnibase agnostic", step B1b): every CRUD of the
 * application, each list as the back office asks it - its default order and its second page, each column of its own
 * as the order, a search, each filter with a value of its own - read three ways on the site's data: the former query on
 * Doctrine's QueryBuilder (createIndexQueryBuilder(), applySearch(), applyFilters(), Doctrine's Paginator), the
 * criteria of the finder (indexCriteria()) through omnibridge/doctrine's repositories, and the same criteria through
 * omnibridge/eloquent's (Base\Admin\Index\RecordPage on each). The rows' ids and the total, compared (FinderParity's
 * outcomes); the neighbouring record of the first row too. Reads only, in a transaction rolled back.
 *
 *     OMNIBRIDGE_ELOQUENT_SITE=1 php bin/phpunit vendor/omnibridge/eloquent/tests/Site/AdminIndexParityTest.php
 *
 * The report goes to var/eloquent-admin-index-parity.json.
 */
final class AdminIndexParityTest extends KernelTestCase
{
    private const PAGE_SIZE = 10;

    private EntityManagerInterface $entityManager;

    private EloquentPersistence $eloquent;

    protected function setUp(): void
    {
        if (!getenv('OMNIBRIDGE_ELOQUENT_SITE')) {
            self::markTestSkipped('Run in an application with OMNIBRIDGE_ELOQUENT_SITE=1.');
        }
        if (!class_exists(\Base\Admin\Index\RecordPage::class) || !method_exists(\Base\Database\Repository\Finder\Group::class, 'of')) {
            self::markTestSkipped('omnibase/admin with its lists on the finder (step B1b) and omnisense/omnibase with Group::of().');
        }
        self::bootKernel();
        $request = Request::create('/admin');
        $request->setSession(new Session(new MockArraySessionStorage()));
        static::getContainer()->get('request_stack')->push($request);
        $this->entityManager = static::getContainer()->get('doctrine')->getManager();
        $this->eloquent = FinderParity::persistence($this->entityManager, (string) static::getContainer()->getParameter('kernel.project_dir'));
    }

    protected function tearDown(): void
    {
        gc_collect_cycles();
        parent::tearDown();
    }

    public function testEveryListReadsTheSameRowsThreeWays(): void
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $former = $doctrine = [];
            foreach ($this->lists() as $name => [$controller, $crud, $sort, $query, $filters, $values, $page, $known]) {
                $former[$name] = [fn () => $this->formerPage($controller, $crud, $sort, $query, $filters, $values, $page), fn () => $this->finderPage($controller, $crud, $sort, $query, $filters, $values, $page, 'doctrine'), !isset($sort['id']), $known];
                $doctrine[$name] = [fn () => $this->finderPage($controller, $crud, $sort, $query, $filters, $values, $page, 'doctrine'), fn () => $this->finderPage($controller, $crud, $sort, $query, $filters, $values, $page, 'eloquent'), !isset($sort['id'])];
            }
            $neighbours = $this->neighbours();
            $reports = [
                'former query = finder on doctrine' => FinderParity::compare($former, $this->clear(...)),
                'finder on doctrine = finder on eloquent' => FinderParity::compare($doctrine, $this->clear(...)),
                'neighbours' => FinderParity::compare($neighbours, $this->clear(...)),
            ];
        } finally {
            $connection->rollBack();
            $this->entityManager->clear();
        }

        $file = static::getContainer()->getParameter('kernel.project_dir').'/var/eloquent-admin-index-parity.json';
        $all = [];
        foreach ($reports as $part => $report) {
            $all[$part] = ['counts' => FinderParity::counts($report), 'scenarios' => $report];
        }
        file_put_contents($file, json_encode($all, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR)."\n");

        self::assertGreaterThan(100, \count($reports['former query = finder on doctrine']));
        foreach ($reports as $part => $report) {
            self::assertSame([], array_keys(FinderParity::unequal($report)), $part.': '.json_encode(FinderParity::unequal($report), \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR));
        }
        // A filter the former query refused (a to-many association filtered by one id, a text filter on an association,
        // a property that is no field: a 500 page) is refused by both finders, and only those.
        $refused = static fn (array $report): array => array_keys(array_filter($report, static fn (array $entry): bool => FinderParity::REFUSED === $entry['outcome']));
        self::assertSame($refused($reports['former query = finder on doctrine']), $refused($reports['finder on doctrine = finder on eloquent']));
    }

    /**
     * Each list as the back office asks it: [controller, crud, order, search, filters, their values, page, the reason the
     * former query and the finder differ, when they do].
     *
     * @return array<string, array{0: object, 1: \Base\Admin\Config\Crud, 2: array<string, string>, 3: string, 4: \Base\Admin\Filter\Filters, 5: array<string, mixed>, 6: int, 7: string|null}>
     */
    private function lists(): array
    {
        $lists = [];
        $registry = static::getContainer()->get(\Base\Admin\Router\AdminRouteRegistry::class);
        foreach (array_keys($registry->getControllers()) as $class) {
            // A list whose repository reads no finder (a plain Doctrine EntityRepository) stays on the former query.
            if (!method_exists($this->repositories('doctrine')->of($class::getEntityFqcn()), '__call')) {
                continue;
            }
            $controller = static::getContainer()->get($class);
            $short = substr($class, strrpos($class, '\\') + 1);
            $crud = $this->call($controller, 'getCrudConfig', \Base\Admin\Config\Crud::PAGE_INDEX, \Base\Admin\Config\Action::INDEX)->setPaginatorPageSize(self::PAGE_SIZE);
            $filters = $this->call($controller, 'getFiltersConfig');
            $default = $crud->getDefaultSort();
            $shape = $this->call($controller, 'shape', $controller::getEntityFqcn());

            $lists[$short.' default'] = [$controller, $crud, $default, '', $filters, [], 1, null];
            $lists[$short.' default, page 2'] = [$controller, $crud, $default, '', $filters, [], 2, null];
            foreach ($this->call($controller, 'getFields', \Base\Admin\Config\Crud::PAGE_INDEX) as $field) {
                $property = $field->getAsDto()->getProperty();
                if ('id' !== $property && $shape->hasField($property)) {
                    $lists[$short.' by '.$property] = [$controller, $crud, [$property => 'DESC', 'id' => 'ASC'], '', $filters, [], 1, null];
                }
            }
            foreach (['a', 'E', 'zzqx'] as $words) {
                $lists[$short.' searched "'.$words.'"'] = [$controller, $crud, ['id' => 'DESC'], $words, $filters, [], 1, null];
            }
            foreach ($filters->getAll() as $property => $filter) {
                // A text filter on a to-many association (Filters::add('owners')): its equality, which DQL refuses.
                $known = \Base\Admin\Filter\Filter::TYPE_TEXT === $filter->getType() && $shape->isToMany($property)
                    ? 'A text filter\'s equality on a to-many association: the former DQL compared the collection itself (a semantical error, the list a 500 page); the finder joins it and compares its rows.'
                    : null;
                foreach ($this->valuesOf($filter) as $label => $value) {
                    $lists[$short.' filtered by '.$property.' '.$label] = [$controller, $crud, ['id' => 'DESC'], '', $filters, [$property => $value], 1, $known];
                }
            }
        }

        return $lists;
    }

    /** @return array<string, mixed> a filter's values: each kind's comparisons, a choice's first and last value */
    private function valuesOf(\Base\Admin\Filter\Filter $filter): array
    {
        $choices = array_keys($filter->getChoices());

        return match ($filter->getType()) {
            \Base\Admin\Filter\Filter::TYPE_BOOLEAN => ['yes' => '1', 'no' => '0'],
            \Base\Admin\Filter\Filter::TYPE_DATE => ['from' => ['from' => '2024-01-01'], 'to' => ['to' => '2025-06-30'], 'between' => ['from' => '2023-01-01', 'to' => '2026-12-31']],
            \Base\Admin\Filter\Filter::TYPE_NUMERIC => ['= 1' => '1', '> 1' => ['comparison' => 'gt', 'value' => '1'], '< 3' => ['comparison' => 'lt', 'value' => '3'], '!= 1' => ['comparison' => 'neq', 'value' => '1']],
            \Base\Admin\Filter\Filter::TYPE_CHOICE, \Base\Admin\Filter\Filter::TYPE_ASSOCIATION => [] === $choices ? [] : ['first' => (string) $choices[0], 'last' => (string) end($choices)],
            default => ['contains a' => 'a', 'equal' => ['comparison' => 'eq', 'value' => 'fr'], 'not equal' => ['comparison' => 'neq', 'value' => 'fr']],
        };
    }

    /** The neighbouring records of each list's newest row and of its fifth, on both persistences. */
    private function neighbours(): array
    {
        $scenarios = [];
        $registry = static::getContainer()->get(\Base\Admin\Router\AdminRouteRegistry::class);
        foreach (array_keys($registry->getControllers()) as $class) {
            $entity = $class::getEntityFqcn();
            $rows = $this->entityManager->getRepository($entity)->findBy([], ['id' => 'DESC'], 5);
            foreach (array_filter([$rows[0] ?? null, $rows[4] ?? null]) as $row) {
                foreach (['prev' => ['GreaterThan', 'ASC'], 'next' => ['LowerThan', 'DESC']] as $direction => [$word, $order]) {
                    $criteria = [\Base\Database\Repository\Finder\Group::of('And', \Base\Database\Repository\Finder\Criterion::of('id', $row->getId(), $word))];
                    $former = function () use ($entity, $row, $direction): mixed {
                        $queryBuilder = $this->entityManager->createQueryBuilder()->select('e.id')->from($entity, 'e')->setMaxResults(1)->setParameter('id', $row->getId());
                        'prev' === $direction ? $queryBuilder->where('e.id > :id')->orderBy('e.id', 'ASC') : $queryBuilder->where('e.id < :id')->orderBy('e.id', 'DESC');

                        return $queryBuilder->getQuery()->getOneOrNullResult()['id'] ?? null;
                    };
                    $name = substr($class, strrpos($class, '\\') + 1).' '.$row->getId().' '.$direction;
                    $scenarios[$name.' (former = doctrine)'] = [$former, fn () => $this->repositories('doctrine')->of($entity)->findOneBy($criteria, ['id' => $order])?->getId()];
                    $scenarios[$name.' (doctrine = eloquent)'] = [fn () => $this->repositories('doctrine')->of($entity)->findOneBy($criteria, ['id' => $order])?->getId(), fn () => $this->repositories('eloquent')->of($entity)->findOneBy($criteria, ['id' => $order])?->getId()];
                }
            }
        }

        return $scenarios;
    }

    /** The former query: Doctrine's QueryBuilder, its search and filters, Doctrine's Paginator. */
    private function formerPage(object $controller, \Base\Admin\Config\Crud $crud, array $sort, string $query, \Base\Admin\Filter\Filters $filters, array $values, int $page): array
    {
        $queryBuilder = $controller->createIndexQueryBuilder($crud, $sort);
        if ('' !== $query) {
            $this->call($controller, 'applySearch', $queryBuilder, $crud, $query);
        }
        $this->call($controller, 'applyFilters', $queryBuilder, $filters, $values);

        return $this->read(new \Base\Admin\Orm\Paginator($queryBuilder, $page, $crud->getPaginatorPageSize()));
    }

    /** The finder's criteria (indexCriteria()) through one persistence's repository. */
    private function finderPage(object $controller, \Base\Admin\Config\Crud $crud, array $sort, string $query, \Base\Admin\Filter\Filters $filters, array $values, int $page, string $persistence): array
    {
        $criteria = $this->call($controller, 'indexCriteria', $crud, $query, $filters, $values);

        return $this->read(new \Base\Admin\Index\RecordPage($this->repositories($persistence)->of($controller::getEntityFqcn()), $criteria, $sort, $page, $crud->getPaginatorPageSize()));
    }

    /** @return array{total: int, ids: list<mixed>} */
    private function read(\IteratorAggregate&\Countable $page): array
    {
        $ids = [];
        foreach ($page as $row) {
            $ids[] = $row->getId();
        }

        return ['total' => $page->getTotal(), 'ids' => $ids];
    }

    private function repositories(string $persistence): \Base\Database\Repository\Repositories
    {
        return 'eloquent' === $persistence ? $this->eloquent->repositories() : new \Omnibridge\Doctrine\Omnibase\DoctrineRepositories($this->entityManager);
    }

    private function call(object $controller, string $method, mixed ...$arguments): mixed
    {
        return (new \ReflectionMethod($controller, $method))->invoke($controller, ...$arguments);
    }

    private function clear(): void
    {
        $this->eloquent->store()->clear();
    }
}
