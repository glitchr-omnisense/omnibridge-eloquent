<?php

namespace Omnibridge\Eloquent\Omnibase;

use Base\Database\Schema\EntitySchema;
use Illuminate\Database\Connection;
use Omnibridge\Eloquent\Casts;
use Omnibridge\Eloquent\EloquentUnitOfWork;
use Omnibridge\Eloquent\EntityStore;
use Omnibridge\Eloquent\Mapping;
use Omnibridge\Schema\Description;

/**
 * omnibase's entities on an Eloquent connection, in one call (plan "omnibase agnostic", step F7b): the
 * description of the core's and the application's entities (EntitySchema, no other persistence asked), the
 * store reading them with omnibase's column types (OmnibaseCasts), the unit of work, the backend and its scopes,
 * the repositories.
 *
 *     $persistence = EloquentPersistence::create(Connections::url($_ENV['DATABASE_URL']), ['project_dir' => $projectDir]);
 *     $article = $persistence->repositories()->oneById(Article::class, 12);
 *
 * Options: EntitySchema's (project_dir, enumerations, types, resolve_target_entities, rules, column_types) and
 * `directories` (more entity folders, a bundle's).
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
        $unitOfWork = new EloquentUnitOfWork($store);

        return new self($store, $unitOfWork, new EloquentBackend($unitOfWork), new EloquentRepositories($store));
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
