<?php

namespace Omnibridge\Eloquent\Omnibase;

use Base\Attributes\AttributeReader;
use Base\Database\Schema\EntitySchema;
use Illuminate\Database\Connection;
use Omnibridge\Eloquent\Cascades;
use Omnibridge\Eloquent\Casts;
use Omnibridge\Eloquent\Connections;
use Omnibridge\Eloquent\EloquentUnitOfWork;
use Omnibridge\Eloquent\EntityStore;
use Omnibridge\Eloquent\Mapping;
use Omnibridge\Eloquent\Omnibase\Lifecycle\OmnibaseListeners;
use Omnibridge\Eloquent\UnsupportedPersistence;
use Omnibridge\Schema\Description;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Omnibridge\Persistence\Lifecycle\Listeners;

/**
 * omnibase's entities on an Eloquent connection, in one call (plan "omnibase agnostic", step F7b): the
 * description of the core's and the application's entities (EntitySchema, no other persistence asked), the
 * store reading them with omnibase's column types (OmnibaseCasts), the unit of work, the backend and its scopes,
 * the repositories.
 *
 *     $persistence = EloquentPersistence::create(Connections::url($_ENV['DATABASE_URL']), ['project_dir' => $projectDir]);
 *     $article = $persistence->repositories()->oneById(Article::class, 12);
 *
 * Options: EntitySchema's (project_dir, enumerations, types, resolve_target_entities, rules, column_types),
 * `directories` (more entity folders, a bundle's), `repositories` (an application's repositories on the store, by
 * entity: EloquentRepositories::register()) and `dispatcher` (the host's PSR-14 dispatcher, given the unit of work's
 * Lifecycle events), `lifecycle` (omnibase's behaviours and listeners on the unit of work: OmnibaseListeners'
 * options, step F7c) and `listeners` (the host's packages' listeners of those events, omnisense/omnibridge's
 * Lifecycle\Listeners: an iterable or a closure of callables, made at the first event, called after omnibase's - step B2). The associations cascade as their mapping and omnibase's #[Cascade] and #[OrphanRemoval] say
 * (OmnibaseCascades). The behaviours' parts are registered for the unit of work (EloquentBehaviours).
 */
final class EloquentPersistence
{
    private function __construct(
        private readonly EntityStore $store,
        private readonly EloquentUnitOfWork $unitOfWork,
        private readonly EloquentBackend $backend,
        private readonly EloquentRepositories $repositories,
    ) {
    }

    /** @param array<string, mixed> $options */
    public static function create(Connection $connection, array $options = [], ?Description $description = null, ?Casts $casts = null): self
    {
        $projectDir = rtrim((string) ($options['project_dir'] ?? getcwd()), '/');
        $options['project_dir'] = $projectDir;
        $description ??= EntitySchema::describe(EntitySchema::directories($projectDir, $options['directories'] ?? []), $options);
        $store = new EntityStore($connection, new Mapping($description), $casts ?? OmnibaseCasts::create($options));
        $dispatcher = $options['dispatcher'] ?? null;
        if (null !== $dispatcher && !$dispatcher instanceof EventDispatcherInterface) {
            throw new \InvalidArgumentException(sprintf('The option "dispatcher" is a %s, no PSR-14 event dispatcher.', get_debug_type($dispatcher)));
        }
        $cascades = new Cascades();
        $cascades->extend(OmnibaseCascades::resolver());
        $unitOfWork = new EloquentUnitOfWork($store, $dispatcher, $cascades);

        $persistence = new self($store, $unitOfWork, new EloquentBackend($unitOfWork), new EloquentRepositories($store, $options['repositories'] ?? [], $unitOfWork));
        // The behaviours' parts (step F7c), asked through omnisense/omnibase's Backends by this unit of work.
        EloquentBehaviours::ensureRegistered();
        EloquentBehaviours::remember($persistence);
        if (\is_array($options['lifecycle'] ?? null)) {
            $persistence->lifecycle = OmnibaseListeners::attach($unitOfWork, $options['lifecycle']);
        }
        // The listeners the host's packages declare (omnisense/omnibridge's Lifecycle\Listeners, step B2), after
        // omnibase's behaviours: made at the first event.
        if (null !== ($options['listeners'] ?? null)) {
            if (!is_iterable($options['listeners']) && !$options['listeners'] instanceof \Closure) {
                throw new \InvalidArgumentException(sprintf('The option "listeners" is a %s, neither an iterable nor a closure of the lifecycle listeners.', get_debug_type($options['listeners'])));
            }
            $unitOfWork->listen(Listeners::chain($options['listeners']));
        }

        return $persistence;
    }

    /**
     * The services of a host omnibase's lifecycle is attached with, by OmnibaseListeners' option (step F7d): each asked
     * of the host's container when it is first needed, since most are made with this persistence's unit of work.
     */
    public const HOST_SERVICES = [
        'attribute_reader' => 'Base\Attributes\AttributeReader',
        'mention_enhancer' => 'Base\Service\Model\Wysiwyg\MentionEnhancerInterface',
        'setting_bag' => 'Base\Service\SettingBagInterface',
        'translator' => 'Base\Translation\OverridingTranslator',
        'user_subscriber' => 'Base\EntitySubscriber\UserSubscriber',
        'demo_lock' => 'Base\Demo\DemoAccountLock',
        'entity_extension' => 'Base\Database\Entity\EntityExtension',
        'requests' => 'Omnibridge\Contract\Requests',
    ];

    /**
     * omnibase's persistence as a host makes it, the one its container gives (plan "omnibase agnostic", step F7d:
     * omnibridge/symfony with `base.persistence: eloquent`, omnibridge/laravel, Omnibridge\Container): on the host's
     * connection, with the options (EntitySchema's, `directories`, `repositories`, `dispatcher`) and omnibase's
     * lifecycle attached from the host's services (HOST_SERVICES, each found by has() now and made by get() when it is
     * first needed: they are made with this persistence) - `lifecycle` gives more of OmnibaseListeners' options over
     * them: `empty_trash`, `max_revisions` (the host's base.extension.* parameters), `entity_dispatchers` (the host's
     * own, an iterable or a closure of them: their life is the container's). Without an AttributeReader (Doctrine's
     * metadata builds it: none in plain PHP), the behaviours cannot run: the entities are read as they are stored and
     * a flush that writes is refused aloud.
     *
     * @param array<string, mixed> $options
     */
    public static function host(Connection $connection, ContainerInterface $services, array $options = [], ?Description $description = null): self
    {
        $lifecycle = [];
        foreach (self::HOST_SERVICES as $option => $id) {
            if ('requests' === $option) {
                $services->has($id) && $lifecycle['requests'] = $services->get($id);
                continue;
            }
            if ($services->has($id)) {
                $lifecycle[$option] = static fn (): mixed => $services->get($id);
            }
        }
        $lifecycle = (\is_array($options['lifecycle'] ?? null) ? $options['lifecycle'] : []) + $lifecycle;
        $lifecycle += ['empty_trash' => '+7 days', 'max_revisions' => 5];
        $withoutReader = !isset($lifecycle['attribute_reader']) && !AttributeReader::hasInstance();
        $options['lifecycle'] = $lifecycle;
        $persistence = self::create($connection, $options, $description);
        if ($withoutReader) {
            $persistence->unitOfWork()->listen(static function (object $event): void {
                if ($event instanceof \Omnibridge\Persistence\Lifecycle\OnFlush && $event->unitOfWork instanceof EloquentUnitOfWork
                    && [] !== [...$event->unitOfWork->scheduledInsertions(), ...$event->unitOfWork->scheduledUpdates(), ...$event->unitOfWork->scheduledDeletions()]) {
                    throw UnsupportedPersistence::operation('write without omnibase\'s behaviours', 'the host has no AttributeReader (made on Doctrine\'s metadata, under Symfony and Laravel): reads only');
                }
            });
        }

        return $persistence;
    }

    /**
     * omnibase's persistence in plain PHP (Omnibridge\Container, the definitions of Omnibridge\Eloquent\Container):
     * its connection the container's Illuminate\Database\Connection, else Capsule on the parameter `database.url`,
     * else on DATABASE_URL; its description the container's Omnibridge\Schema\Description, else the entities of
     * `kernel.project_dir` (the current folder by default); the parameters base.extension.empty_trash and
     * max_revisions; the services tagged base.entity_dispatcher, when it has tags (Omnibridge\Container::tagged());
     * the trash scope on, and the vault's for `kernel.environment` (prod by default), as the hosts enable them.
     */
    public static function fromContainer(ContainerInterface $container): self
    {
        $parameter = static fn (string $name, mixed $default = null): mixed => method_exists($container, 'hasParameter') && $container->hasParameter($name) ? $container->getParameter($name) : $default;
        // The host's services among those it defines: Omnibridge\Container answers has() for any class it could
        // autowire (a Connection, an empty Description...), which is not a service the application declared.
        $defined = method_exists($container, 'ids') ? $container->ids() : null;
        $services = null === $defined ? $container : new class($container, $defined) implements ContainerInterface {
            /** @param list<string> $ids */
            public function __construct(private readonly ContainerInterface $container, private readonly array $ids)
            {
            }

            public function get(string $id): mixed
            {
                return $this->container->get($id);
            }

            public function has(string $id): bool
            {
                return \in_array($id, $this->ids, true) || (interface_exists($id) && $this->container->has($id));
            }
        };
        if ($services->has(Connection::class)) {
            $connection = $services->get(Connection::class);
        } else {
            $url = $parameter('database.url') ?? (getenv('DATABASE_URL') ?: ($_ENV['DATABASE_URL'] ?? null));
            $connection = Connections::url(\is_string($url) && '' !== $url ? $url : throw new \InvalidArgumentException('No database for omnibridge/eloquent: give the container an Illuminate\Database\Connection, the parameter "database.url", or DATABASE_URL.'));
        }
        $description = $services->has(Description::class) ? $services->get(Description::class) : null;
        $lifecycle = [
            'empty_trash' => $parameter('base.extension.empty_trash', '+7 days'),
            'max_revisions' => (int) $parameter('base.extension.max_revisions', 5),
        ];
        if (method_exists($container, 'tagged')) {
            $lifecycle['entity_dispatchers'] = static fn (): array => $container->tagged('base.entity_dispatcher');
        }

        $persistence = self::host($connection, $services, [
            'project_dir' => $parameter('kernel.project_dir', getcwd()),
            'lifecycle' => $lifecycle,
            // The packages' listeners of the Lifecycle events (step B2), where the container has tags.
            'listeners' => method_exists($container, 'tagged') ? static fn (): iterable => $container->tagged(Listeners::TAG) : null,
        ], $description);
        // The two scopes the hosts enable, as omnibase's Doctrine mapping enables its filters: the trash, and the vault
        // for the environment (kernel.environment, prod by default).
        $persistence->backend()->enableScope('trash');
        $persistence->backend()->enableScope('vault', ['environment' => (string) $parameter('kernel.environment', 'prod')]);

        return $persistence;
    }

    /**
     * Between two requests or messages (Symfony's kernel.reset, Laravel's scoped reset, a worker's loop): the unit of
     * work cleared - nothing scheduled, no entity managed, a closed one open again -, as Doctrine's registry resets
     * its entity manager.
     */
    public function reset(): void
    {
        $this->unitOfWork->clear();
    }

    private ?OmnibaseListeners $lifecycle = null;

    /** The behaviours' relay and the listeners' adapters attached by the option "lifecycle" (null without it). */
    public function lifecycle(): ?OmnibaseListeners
    {
        return $this->lifecycle;
    }

    public function store(): EntityStore
    {
        return $this->store;
    }

    public function unitOfWork(): EloquentUnitOfWork
    {
        return $this->unitOfWork;
    }

    public function backend(): EloquentBackend
    {
        return $this->backend;
    }

    public function repositories(): EloquentRepositories
    {
        return $this->repositories;
    }
}
