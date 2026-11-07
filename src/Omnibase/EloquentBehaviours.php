<?php

namespace Omnibridge\Eloquent\Omnibase;

use Base\Database\Attribute\Backend\Backends;
use Base\Database\Attribute\Backend\CacheBackend;
use Base\Database\Attribute\Backend\OrderColumnBackend;
use Base\Database\Attribute\Backend\SlugifyBackend;
use Base\Database\Attribute\Backend\VaultBackend;
use Base\Database\Backend\BulkDeletes;
use Base\Database\Backend\ColumnTypes;
use Base\Database\Backend\ConditionalUpdates;
use Base\Database\Backend\EntityCache;
use Base\Database\Backend\RowLocks;
use Base\Database\Mapping\DescriptionShapes;
use Base\Database\Mapping\EntityShapes;
use Base\Database\Repository\Repositories;
use Omnibridge\Eloquent\EloquentUnitOfWork;
use Omnibridge\Eloquent\Omnibase\Behaviour\EloquentBulkDeletes;
use Omnibridge\Eloquent\Omnibase\Behaviour\EloquentCacheBackend;
use Omnibridge\Eloquent\Omnibase\Behaviour\EloquentColumnTypes;
use Omnibridge\Eloquent\Omnibase\Behaviour\EloquentConditionalUpdates;
use Omnibridge\Eloquent\Omnibase\Behaviour\EloquentEntityCache;
use Omnibridge\Eloquent\Omnibase\Behaviour\EloquentOrderColumnBackend;
use Omnibridge\Eloquent\Omnibase\Behaviour\EloquentRowLocks;
use Omnibridge\Eloquent\Omnibase\Behaviour\EloquentSlugifyBackend;
use Omnibridge\Eloquent\Omnibase\Behaviour\EloquentVaultBackend;
use Omnibridge\Persistence\Backend;

/**
 * The behaviours' parts on omnibridge/eloquent (plan "omnibase agnostic", step F7c), registered for EloquentUnitOfWork
 * with omnisense/omnibase's Backends where omnibridge/doctrine's DoctrineBehaviours registers Doctrine's: #[Vault]'s
 * forced update, #[Slugify]'s pending writes and unique index, #[Cache]'s eviction (no second-level cache), the
 * #[OrderColumn]'s lazy order; the entities' shapes (the store's description); what the core's services ask beyond
 * the unit of work (row locks, conditional updates, the entity cache, deletes by criteria); the column types holding
 * arrays; the backend and its scopes; the repositories (the persistence's own, EloquentPersistence's).
 */
final class EloquentBehaviours
{
    public const BACKENDS = [
        VaultBackend::class => EloquentVaultBackend::class,
        SlugifyBackend::class => EloquentSlugifyBackend::class,
        CacheBackend::class => EloquentCacheBackend::class,
        OrderColumnBackend::class => EloquentOrderColumnBackend::class,
        RowLocks::class => EloquentRowLocks::class,
        ConditionalUpdates::class => EloquentConditionalUpdates::class,
        EntityCache::class => EloquentEntityCache::class,
        BulkDeletes::class => EloquentBulkDeletes::class,
        ColumnTypes::class => EloquentColumnTypes::class,
    ];

    private static bool $registered = false;

    /** @var \WeakMap<EloquentUnitOfWork, \WeakReference<EloquentPersistence>>|null */
    private static ?\WeakMap $persistences = null;

    public static function register(): void
    {
        self::$registered = true;
        foreach (self::BACKENDS as $behaviour => $backend) {
            Backends::register(EloquentUnitOfWork::class, $behaviour, $backend);
        }
        Backends::register(EloquentUnitOfWork::class, EntityShapes::class, static fn (EloquentUnitOfWork $unitOfWork) => new DescriptionShapes($unitOfWork->store()->mapping()->description));
        Backends::register(EloquentUnitOfWork::class, Backend::class, static fn (EloquentUnitOfWork $unitOfWork) => self::persistence($unitOfWork)?->backend() ?? new EloquentBackend($unitOfWork));
        Backends::register(EloquentUnitOfWork::class, Repositories::class, static fn (EloquentUnitOfWork $unitOfWork) => self::persistence($unitOfWork)?->repositories() ?? new EloquentRepositories($unitOfWork->store(), [], $unitOfWork));
    }

    public static function ensureRegistered(): void
    {
        if (!self::$registered) {
            self::register();
        }
    }

    /** The persistence a unit of work belongs to: its backend and repositories are the ones its behaviours ask for. */
    public static function remember(EloquentPersistence $persistence): void
    {
        self::$persistences ??= new \WeakMap();
        // A weak reference: the persistence holds its unit of work, which would keep both alive otherwise.
        self::$persistences[$persistence->unitOfWork()] = \WeakReference::create($persistence);
    }

    public static function persistence(EloquentUnitOfWork $unitOfWork): ?EloquentPersistence
    {
        return isset(self::$persistences[$unitOfWork]) ? self::$persistences[$unitOfWork]->get() : null;
    }
}
