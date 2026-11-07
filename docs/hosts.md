# The persistence of a host

omnibridge/eloquent runs where omnibase runs: under Symfony, under Laravel, in plain PHP (omnisense/omnibase's plan
"omnibase agnostic", step F7d). The persistence is chosen as every host chooses it - omnisense/omnibridge's
`Persistence\Persistences`: the only persistence installed, else the one the host's option names; omnibridge/doctrine
and omnibridge/eloquent both installed with no option is **refused aloud**, never one picked for you (R8). Where the
option names none, the environment variable `OMNIBRIDGE_PERSISTENCE` may.

| Host | Option | Wired by |
|---|---|---|
| Symfony | `base: { persistence: eloquent }` (config/packages/base.yaml) | omnibridge/symfony |
| Laravel | `'persistence' => 'eloquent'` (config/base.php) | omnibridge/laravel |
| plain PHP | the parameter `persistence` of `Omnibridge\Container::fromDefinitions()` | `Omnibridge\Eloquent\Container::definitions()` |

## What a host gets

Behind the same contracts as Doctrine's:

- `Omnibridge\Persistence\UnitOfWork` and `Snapshots`: the `EloquentUnitOfWork`;
- `Omnibridge\Persistence\Backend`: the `EloquentBackend`, its trash scope on and its vault scope for the environment,
  as omnibase's Doctrine mapping enables its filters;
- `Base\Database\Repository\Repositories`: the `EloquentRepositories`;
- each of the core's repository interfaces (`Base\Discovery`'s `repositories`): the repository implementing it,
  `Base\Repository\Users` reading the application's users (`App\Entity\User`) - Security's user loader and provider
  too (`loadUserByIdentifier()`, `refreshUser()`, `supportsClass()`, `upgradePassword()`, as omnibridge/doctrine's).

`EloquentPersistence::host($connection, $services, $options)` makes it: omnibase's lifecycle is attached from the host's
services (`EloquentPersistence::HOST_SERVICES`: the AttributeReader, the mention enhancer, the setting bag, the
overriding translator, UserSubscriber, the demonstration lock, the entities' extension, the requests), each found by
`has()` when the persistence is made and asked by `get()` when it is first needed - most are made with this
persistence's unit of work. The entity dispatchers are the host's own services (an iterable or a closure, read at the
first entity event): they live as long as the container that holds the persistence, so the host's flush of what they
left pending never reaches another persistence's unit of work. `reset()` clears the unit of work between two requests
(Symfony's kernel.reset), as Doctrine's registry resets its entity manager.

Without an AttributeReader (omnibase builds it on Doctrine's metadata: none in plain PHP) the behaviours cannot run:
the entities are read as stored, and a flush that writes is refused aloud (`UnsupportedPersistence`).

## Symfony

omnibridge/symfony registers the services when `base.persistence` is `eloquent`: the connection is Doctrine's PDO when
DoctrineBundle is installed beside (one database, one transaction), else Capsule on DATABASE_URL; the entities are
those DoctrineBundle maps (their folders, target entities, column types), else the core's and src/Entity. An `entity`
user provider of security.yaml for the application's users (no `property`) becomes the users' repository on Eloquent.
What still names Doctrine's services by their ids (doctrine.orm.entity_manager, a repository by its class) stays on
Doctrine.

## Laravel

omnibridge/laravel answers the same contracts from Eloquent when config/base.php says so, on Laravel's own connection
(`omnibridge.eloquent.connection`, else `omnibridge.doctrine.connection`, else the default one); the account's effects
(the connections' tracker, the sign-in listeners, omniauth's stores) write through the unit of work chosen.

## Plain PHP

```php
$container = Omnibridge\Container::fromDefinitions([], [
    'persistence' => 'eloquent',
    'kernel.project_dir' => '/srv/app',          // the entities of src/Entity and the core's
    'database.url' => getenv('DATABASE_URL'),    // Capsule (or give an Illuminate\Database\Connection service)
]);
$articles = $container->get(Base\Database\Repository\Repositories::class)->of(App\Entity\Article\Article::class)->findAll();
```

`EloquentPersistence::fromContainer()` reads the container's connection or `database.url` or DATABASE_URL, its
`Omnibridge\Schema\Description` or the entities' folders, `base.extension.empty_trash`/`max_revisions`, the services
tagged `base.entity_dispatcher`, and only the services the application defined (Omnibridge\Container answers `has()`
for any class it could autowire). `tests/Site/articles.php` lists lesringostars' articles so, with no Symfony, no
Laravel and no Doctrine entity manager.
