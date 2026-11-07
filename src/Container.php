<?php

namespace Omnibridge\Eloquent;

use Omnibridge\Eloquent\Omnibase\EloquentBackend;
use Omnibridge\Eloquent\Omnibase\EloquentPersistence;
use Omnibridge\Eloquent\Omnibase\EloquentRepositories;

/**
 * omnibridge/eloquent's services declared as data, for omnisense/omnibridge's Omnibridge\Container (plan "omnibase
 * agnostic", step F7d: the persistence in plain PHP), which adds them when it runs on Eloquent
 * (Persistence\Persistences: the parameter `persistence`, or Eloquent the only persistence installed).
 *
 * With omnisense/omnibase: its persistence (EloquentPersistence::fromContainer(): Capsule on `database.url` or
 * DATABASE_URL unless the container gives a connection, omnibase's lifecycle on the container's services), its unit
 * of work as UnitOfWork and Snapshots, its backend as Backend, its repositories as Repositories, and each repository
 * interface of the core (Base\Discovery's `repositories`) answered by the repository implementing it. Without the core,
 * nothing: the store needs a description, which the application builds (EntityStore, EloquentUnitOfWork).
 *
 * Symfony and Laravel wire the same pieces from their own configuration (omnibridge/symfony, omnibridge/laravel).
 */
final class Container
{
    /** @return list<array<string, mixed>> Omnibridge\Container\Declarations' format */
    public static function definitions(): array
    {
        if (!class_exists(\Base\Database\Schema\EntitySchema::class)) {
            return [];
        }
        $persistence = '@'.EloquentPersistence::class;
        $definitions = [
            ['class' => EloquentPersistence::class, 'factory' => [EloquentPersistence::class, 'fromContainer'], 'arguments' => ['@Psr\Container\ContainerInterface']],
            ['class' => EloquentUnitOfWork::class, 'factory' => [$persistence, 'unitOfWork'], 'contracts' => ['Omnibridge\Persistence\UnitOfWork', 'Omnibridge\Persistence\Snapshots']],
            ['class' => EloquentBackend::class, 'factory' => [$persistence, 'backend'], 'contracts' => ['Omnibridge\Persistence\Backend']],
            ['class' => EloquentRepositories::class, 'factory' => [$persistence, 'repositories'], 'contracts' => ['Base\Database\Repository\Repositories']],
        ];
        foreach (self::interfaces() as $interface => $entity) {
            $definitions[] = ['id' => $interface, 'class' => $interface, 'factory' => ['@'.EloquentRepositories::class, 'implementing'], 'arguments' => [$interface, $entity]];
        }

        return $definitions;
    }

    /**
     * The core's repository interfaces a repository answers (Repositories itself aside), each with the entity it reads
     * when it is the application's: Users the application's users (App\Entity\User, as the core's alias names
     * App\Repository\UserRepository), the others their own entity (null).
     *
     * @return array<class-string, class-string|null>
     */
    public static function interfaces(): array
    {
        $registry = class_exists(\Base\Discovery::class) ? \Base\Discovery::registry('repositories') : \Base\Repository\Aliases::INTERFACES;
        $interfaces = [];
        foreach ($registry as $interface => $repository) {
            if ('Base\Database\Repository\Repositories' === $interface) {
                continue;
            }
            $entity = null;
            if (str_starts_with((string) $repository, 'App\\Repository\\') && class_exists($application = 'App\\Entity\\'.substr(substr((string) $repository, 15), 0, -10))) {
                $entity = (new \ReflectionClass($application))->getName();
            }
            $interfaces[$interface] = $entity;
        }

        return $interfaces;
    }
}
