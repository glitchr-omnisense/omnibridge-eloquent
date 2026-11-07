---
title: omnibase's behaviours
order: 45
---

# omnibase's behaviours on Eloquent

Step F7c of omnisense/omnibase's plan "omnibase agnostic", second part: the behaviour attributes (`#[Slugify]`,
`#[Timestamp]`, `#[Uploader]`, `#[Vault]`...) and the listeners omnibridge/doctrine and omnibase register on Doctrine's
events run on the unit of work's Lifecycle events, at the same moments, and write the same rows.

```php
$persistence = EloquentPersistence::create($connection, ['project_dir' => $projectDir, 'lifecycle' => [
    'empty_trash' => '+7 days', 'max_revisions' => 5,
    'mention_enhancer' => fn () => $mentionEnhancer, 'setting_bag' => fn () => $settingBag,
    'translator' => fn () => $overridingTranslator, 'dispatcher' => $psr14, 'requests' => $requests,
    'entity_dispatchers' => [UserEventDispatcher::class, ThreadEventDispatcher::class],
    'user_subscriber' => fn () => $userSubscriber, 'entity_extension' => $entityExtension,
]]);
```

The host wires these from its container (step F7d); `tests/Support/OmnibaseHarness` does it from a Symfony test
container.

## The relay (`Omnibase\AttributeRelay`)

omnibridge/doctrine's `AttributeSubscriber` on the unit of work (a `listen()` listener), kept as it is:

- an entity's moment (PrePersist ... PostRemove, PostLoad): each property attribute, then each class attribute, that
  supports the entity, gets its neutral hook;
- the flush's moments over its buffers: PreFlush and OnFlush both append the scheduled insertions, updates and
  deletions without emptying them (an entity persisted before the flush is there twice at OnFlush, and its OnFlush
  hooks run twice, as on Doctrine); a property attribute runs when its property changed or, for a new entity without
  an identifier, when the entity has it; PostFlush walks the candidates and the buffers, then empties them;
- an attribute overriding a Doctrine-typed hook of the moment (`onFlush(OnFlushEventArgs ...)`: omnibase/marketplace's
  `OrderReference`) is refused aloud (`UnsupportedPersistence`).

## The behaviours' parts (`Omnibase\EloquentBehaviours`)

Registered with omnibase's `Backends` for `EloquentUnitOfWork`, where omnibridge/doctrine's `DoctrineBehaviours`
registers Doctrine's:

| Part | On Eloquent |
|---|---|
| `VaultBackend` | `scheduleForUpdate()`: the entity updated whatever its change set says |
| `SlugifyBackend` | the scheduled insertions then updates; a unique index on the column alone read through Illuminate's schema builder |
| `CacheBackend` | no second-level cache: nothing evicted; the cached targets' associations read through their getters, as Doctrine's backend reads them |
| `OrderColumnBackend` | a `LazyCollection` holds its elements, once read, in omnibase's `OrderedArrayCollection` |
| `EntityShapes` | the store's description (`DescriptionShapes`) |
| `RowLocks` | a transaction and `SELECT ... FOR UPDATE` |
| `ConditionalUpdates` | `UPDATE ... WHERE id = ? AND field = ?` |
| `EntityCache` | disabled |
| `BulkDeletes` | `DELETE` by criteria, every table of a joined hierarchy (`deleteIds()` too) |
| `ColumnTypes` | `json` and the set enumerations |
| `Backend`, `Repositories` | the persistence's own |

## The listeners (`Omnibase\Lifecycle\`)

Listening in Doctrine's order (priority, then declaration): the relay and `IntlListener` (2048), `TrasheableListener`
(1024), `VersionableListener` (512), then `ThreadListener`, `SettingListener`, `TextOverrideListener`, one
`EntityDispatcherListener` per entity dispatcher, `PreFlushListener` (UserSubscriber's `preFlush()`),
`DemoAccountLockListener`.

| Doctrine listener | Eloquent | Rule (the core's) |
|---|---|---|
| TrasheableSubscriber | `TrasheableListener`: the stamp, the deletion taken back, an extra update with the pending changes, the cascade rescued, the trash entry inserted | `TrashRecorder` |
| VersionableSubscriber | `VersionableListener`: a revision per anchor at OnFlush, the old ones deleted after the flush | `RevisionRecorder` |
| IntlSubscriber (flush, postLoad) | `IntlListener` | - |
| ThreadSubscriber (prePersist, onFlush) | `ThreadListener`; the mentionees read again from the store (`StoreMentionees`) | `ThreadParticipants` |
| SettingSubscriber | `SettingListener` | `SettingsInvalidation` |
| TextOverrideCacheListener | `TextOverrideListener` | - |
| the entity dispatchers (user.\*, thread.\*, an application's) | `EntityDispatcherListener`: doctrine/persistence's `LifecycleEventArgs` over an `EloquentObjectManager`, a PreUpdate's with its changes (`PreUpdateArgs`) | - |
| UserSubscriber's preFlush | `PreFlushListener` | - |
| DemoAccountLockSubscriber | `DemoAccountLockListener` | `DemoAccountLock` |

Not adapted, with the reason: InverseCollectionCacheSubscriber and TrasheableSubscriber's eviction (Doctrine's
second-level cache: the store keeps none); TrackingPolicySubscriber, the listeners' `loadClassMetadata` and
`resolveDiscriminator` (Doctrine's mapping: the description is the store's; the translations' cascades are
`OmnibaseCascades`'); IntlSubscriber's `onQuery` (the finder joins the translations itself). Refused aloud:
ExtensionSubscriber's entries when an `EntityExtension` holds an extension (none is registered by omnibase, its bundles
or lesringostars), an entity dispatcher overriding the Doctrine-typed `postFlush()`, the entity dispatchers without
doctrine/persistence, `EloquentObjectManager`'s metadata and repositories.

## Known differences

- `TrasheableListener` compares an enumeration field as the entity holds it; Doctrine's subscriber reads its backing
  value and finds it changed: the same column written, Doctrine's next flush of that entity updating it once more.
