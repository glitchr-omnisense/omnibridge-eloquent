<?php

/*
 * lesringostars' articles listed in plain PHP (omnisense/omnibase's plan "omnibase agnostic", step F7d; Marco's answer
 * 10: Eloquent outside Laravel): no kernel, no container of Symfony's nor Laravel's, no Doctrine - Composer's autoloader,
 * omnibridge/eloquent's classes (tests/Site/autoload.php: Illuminate's database and Capsule), and omnisense/omnibridge's
 * Omnibridge\Container told to run on Eloquent (its parameter `persistence`): the container finds omnibridge/eloquent's
 * services (Omnibridge\Eloquent\Container), its persistence opens Capsule on DATABASE_URL and describes the site's
 * entities from their folders, the articles are read through the core's Repositories, the trash scope on.
 *
 *   docker exec lesringostars-web-1 php vendor/omnibridge/eloquent/tests/Site/articles.php [--json]
 *
 * Prints the articles (id, title, state), their count, and what PHP loaded of a framework or of Doctrine's ORM on the
 * way (none expected). Reads only.
 */

use Base\Database\Repository\Repositories;
use Omnibridge\Container;

$project = getenv('OMNIBASE_PROJECT_DIR') ?: dirname(__DIR__, 5);
require $project.'/vendor/autoload.php';
require __DIR__.'/autoload.php';

$url = getenv('DATABASE_URL');
if (!is_string($url) || '' === $url) {
    fwrite(STDERR, "DATABASE_URL is not set.\n");
    exit(2);
}

// The page's language, as a host gives it (the Locales contract): the site's French; its titles are read in it.
final class SiteLocales implements Omnibridge\Contract\Locales
{
    public function getLocale(): string
    {
        return 'fr-FR';
    }

    public function getDefaultLocale(): string
    {
        return 'fr-FR';
    }

    public function getAvailableLocales(): array
    {
        return ['fr-FR'];
    }
}

$container = Container::fromDefinitions([], [
    'persistence' => 'eloquent',
    'kernel.project_dir' => $project,
    'kernel.environment' => getenv('APP_ENV') ?: 'dev',
    'database.url' => $url,
]);
$container->set(Omnibridge\Contract\Locales::class, new SiteLocales());
Omnibridge\Runtime::setContainer($container);
$articles = $container->get(Repositories::class)->of('App\Entity\Article\Article')->findBy([], ['id' => 'ASC']);
$rows = array_map(static fn (object $article): array => [
    'id' => $article->getId(),
    'title' => (string) $article->getTitle(),
    'state' => $article->getState() instanceof \BackedEnum ? $article->getState()->value : $article->getState(),
], $articles);

$loaded = [];
foreach ([...get_declared_classes(), ...get_declared_interfaces()] as $class) {
    foreach (['Symfony\Component\HttpKernel\\', 'Symfony\Component\DependencyInjection\\', 'Symfony\Bundle\\', 'Symfony\Component\HttpFoundation\\', 'Illuminate\Foundation\\', 'Illuminate\Http\\', 'Doctrine\ORM\\', 'Doctrine\DBAL\\', 'Omnibridge\Symfony\\', 'Omnibridge\Laravel\\', 'Omnibridge\Doctrine\\'] as $prefix) {
        if (str_starts_with($class, $prefix)) {
            $loaded[$prefix][] = $class;
        }
    }
}
$report = [
    'persistence' => $container->getParameter('persistence'),
    'unit of work' => get_debug_type($container->get(Omnibridge\Persistence\UnitOfWork::class)),
    'count' => count($rows),
    'articles' => $rows,
    'framework or ORM loaded' => $loaded,
];
if (in_array('--json', $argv, true)) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    exit(0);
}
printf("%d articles through %s (%s)\n", $report['count'], $report['unit of work'], $report['persistence']);
foreach ($rows as $row) {
    printf("%5d  %-10s %s\n", $row['id'], $row['state'], $row['title']);
}
printf("framework or ORM loaded: %s\n", [] === $loaded ? 'none' : json_encode(array_map('count', $loaded)));
