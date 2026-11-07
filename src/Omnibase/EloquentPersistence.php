<?php

namespace Omnibridge\Eloquent\Omnibase;

use Base\Database\Schema\EntitySchema;
use Illuminate\Database\Connection;
use Omnibridge\Eloquent\Cascades;
use Omnibridge\Eloquent\Casts;
use Omnibridge\Eloquent\EloquentUnitOfWork;
use Omnibridge\Eloquent\EntityStore;
use Omnibridge\Eloquent\Mapping;
use Omnibridge\Eloquent\Omnibase\Lifecycle\OmnibaseListeners;
use Omnibridge\Schema\Description;
use Psr\EventDispatcher\EventDispatcherInterface;

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
 * Lifecycle events) and `lifecycle` (omnibase's behaviours and listeners on the unit of work: OmnibaseListeners'
 * options, step F7c). The associations cascade as their mapping and omnibase's #[Cascade] and #[OrphanRemoval] say
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

        return $persistence;
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
