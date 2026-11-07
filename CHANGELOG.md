# Changelog

## [Unreleased]

### Added: the back office's lists on both compilers (omnisense/omnibase's plan "omnibase agnostic", step B1b)

- `tests/Site/AdminIndexParityTest`: every list of an application's omnibase/admin - its default order and second page,
  each column as the order, a search, each filter with values of its own - read by the former query on Doctrine's
  QueryBuilder, by the finder's criteria (`indexCriteria()`, `Group::of()`) through omnibridge/doctrine and through
  omnibridge/eloquent (`RecordPage`), the neighbouring records too: on lesringostars 386 lists equal three ways (but the
  16 equalities on a to-many association the former DQL refused, and the 17 filters all three refuse alike), 136
  neighbours equal.
- `FinderTest` and both `FinderParityTest`s (harness, site) read groups built from their members (`Group::of()`):
  nested, a key, the comparisons, the dates, the neighbour by id. Nothing to compile anew: such a group is a group read.
- `tests/Site/autoload.php` registers the tests' classes where the application installs the package (its lock, since
  F7): the site's tests found no `Tests\Support` class.

### Added: the host's packages hear the writes (omnisense/omnibase's plan "omnibase agnostic", step B2)

- `EloquentPersistence::create()`'s option `listeners`: the listeners of the Lifecycle events a host's packages declare
  (omnisense/omnibridge's `Lifecycle\Listeners`, the tag `omnibridge.lifecycle_listener`), an iterable or a closure of
  callables, made at the first event and called after omnibase's behaviours. `fromContainer()` gives the plain
  container's tagged services; omnibridge/symfony and omnibridge/laravel give theirs. glitchr/typesense-bundle's index
  follows Eloquent's writes this way (it followed Doctrine's only). `HostTest`: every moment of a write heard, the
  listeners made at the first event; anything else than a list refused.

### Fixed: what lesringostars' full run showed on eloquent (omnisense/omnibase's plan "omnibase agnostic", step F7f-3)

- `Cascades::cascades()`: orphan removal on a one-to-many or one-to-one association implies the removal's cascade, as
  Doctrine completes such a mapping (`isCascadeRemove`). An account removed left its browsers' connections
  (`User::$connections`: `orphanRemoval`, no `cascade` declared), and on MySQL its own delete failed on their foreign
  key. Harness `WriteParityTest::testAnAccountRemovedTakesItsConnections` (the same rows as Doctrine's; it failed
  before).
- `EloquentBackend::supports(Backend::TRANSACTIONS)` is true, as its docblock said since the writes (F7c):
  `transactional()`, the flush's transaction and the row locks.

### Added: the methods no finder compiles, an application's repositories (omnisense/omnibase's plan "omnibase agnostic", step F7f)

- The 14 methods the core declares `#[BackendOnly]` run on Eloquent's connection instead of being refused: `PageViews`
  (`incrementView()`'s upsert - ON DUPLICATE KEY UPDATE on MySQL, ON CONFLICT elsewhere -, the daily and hourly
  breakdowns, by source too), `PageVisits` (`recordPresence()`'s INSERT IGNORE, `countUnique()` over a DISTINCT
  subquery, the breakdowns), `Visits` (`recordPresence()`, `returning()`, the breakdowns), `Redirections::hit()` (one
  `UPDATE ... SET hits = hits + 1, last hit` on the row; the entity in memory left as read, as a DQL UPDATE leaves it).
  omnibridge/doctrine's statements, through `Repository\Analytics\Statements` (the grammar's table, positional
  bindings, the platform by the driver); `returning()` quotes its alias, a keyword of SQLite since 3.35. In eloquent
  mode a page view is counted again and the back office's layout (its unique views) renders.
- `EloquentRepositories::register()` takes a factory, for an application's repository that needs more than the store
  (lesringostars' comments' counts take a cache); `implementing()` answers with the repository that declares the
  interface before a registered subclass that only inherits it (an application's `ArticleRepository` does not answer
  `Threads`), loading the classes in order until that one as before.
- `EntityStore::without($condition, $work)`: a read with a scope lifted (`scope:trash`), as Doctrine's SQL filter
  disabled for one query (lesringostars' `Subscribers::findOneByEmailIncludingTrashed()`).
- Tests: `Harness\BackendWrittenParityTest`, `Site\BackendWrittenParityTest`, `Site\AppRepositoriesParityTest`
  (`Support\BackendWrittenParity`); RepositoriesTest runs the 14 on SQLite.

### Fixed: what the twin and lesringostars showed in eloquent mode (omnisense/omnibase's plan "omnibase agnostic", steps F7d-2, F7d-3)

- `FinderQuery` is omnibase's `Base\Service\Model\Pages`: `count()` the entities once each, `slice()` a page of
  entities in the query's order (the identifiers first when a join repeats an entity) - what Paginator::paginate()
  takes (a records picker's pages, RemoteSearch: a TypeError before).
- `OmnibaseCascades` reads #[Cascade] and #[OrphanRemoval] on a property that is the association's #[Alias] too
  (lesringostars' `Gallery::$photos`, the children): a photo left out of its gallery is removed (trashed), as on Doctrine.
- `LazyCollection` serializes as Doctrine's PersistentCollection does (the elements read so far, no loader): an entity
  read through the store goes into a session (Symfony's firewall keeps the account; "Serialization of 'Closure' is not
  allowed" before).
- A flush, `transactional()` and the row locks join a transaction the PDO is already in when someone else began it
  (Doctrine's, on the PDO omnibridge/symfony shares): "There is already an active transaction" before.

### Added: the persistence of a host (omnisense/omnibase's plan "omnibase agnostic", step F7d)

- `Container::definitions()`: the services omnisense/omnibridge's `Omnibridge\Container` adds when it runs on Eloquent
  (`Persistences`): the persistence (`EloquentPersistence::fromContainer()`), the unit of work as UnitOfWork and
  Snapshots, the backend, Repositories, the core's repository interfaces; `Container::interfaces()` (each with the
  application's entity it reads: Users the application's users).
- `EloquentPersistence::host()`: omnibase's lifecycle attached from a host's services (`HOST_SERVICES`, each made when
  first needed), the host's own entity dispatchers (an iterable or a closure: their life is the container's);
  without an AttributeReader, reads only and a writing flush refused aloud. `fromContainer()` (plain PHP: Capsule on
  `database.url`/DATABASE_URL, the trash and vault scopes on). `reset()`.
- `EloquentUnitOfWork::clear()`: nothing scheduled, nothing managed, a closed unit of work open again.
- `Omnibase\Lifecycle\EntityDispatchersListener`; `AttributeRelay`, `DemoAccountLockListener` and `OmnibaseListeners`
  take closures (`attribute_reader`, `demo_lock`, `entity_extension`, `entity_dispatchers`), asked when first needed.
- `Omnibase\Repository\UserRepository` is Security's user loader and provider (Base\Contract\SecurityUserLoader,
  SecurityUserProvider): `supportsClass()`, `loadUserByIdentifier()`, `refreshUser()`, `upgradePassword()`, as
  omnibridge/doctrine's.
- `tests/Omnibase/HostTest`; `tests/Site/articles.php`: lesringostars' articles in plain PHP through Capsule.
  Docs: docs/hosts.md.

### Added: lesringostars' write flows compared row by row (omnisense/omnibase's plan "omnibase agnostic", step F7c, third part)

- `tests/Site/FlowParityTest`: seven flows of lesringostars (sign-up, sign-in, comment and reply, newsletter, trash and
  vault, tags and ordered collections, settings) through the services its controllers call, once on Doctrine and once
  on Eloquent, each on its copy of the test database; every table's rows compared step by step, column by column
  (`tests/Support/RowChanges`, `tests/Support/Site/FlowSide`, the App repositories' test doubles
  `EloquentSubscribers` and `EloquentDestinations`, `FlowAccess`, `CapturingSender`). See docs/testing.md.

### Fixed

- `EloquentUnitOfWork`: an entity inserted or updated keeps as its snapshot what it held when its change set was
  computed (or recomputed after PreUpdate), not what PostPersist/PostUpdate listeners left - Doctrine's original data -,
  so the next flush writes their changes as Doctrine's does. lesringostars' sign-up wrote `User::$rolesPositions` once
  more on Doctrine (`[0]`, then `[]` at the next flush) than on Eloquent.

### Added: omnibase's behaviours on the unit of work (omnisense/omnibase's plan "omnibase agnostic", step F7c, second part)

- `Omnibase\AttributeRelay`: omnibridge/doctrine's AttributeSubscriber on the unit of work's Lifecycle events, its
  buffers kept as they are (PreFlush and OnFlush append without emptying, PostFlush walks candidates and buffers); an
  attribute overriding a Doctrine-typed hook of the moment refused aloud. See docs/behaviours.md.
- `Omnibase\EloquentBehaviours` registers with omnibase's Backends, for `EloquentUnitOfWork`, a part wherever Doctrine
  has one: `Omnibase\Behaviour\Eloquent{Vault,Slugify,Cache,OrderColumn}Backend`, `EloquentRowLocks`,
  `EloquentConditionalUpdates`, `EloquentEntityCache` (disabled), `EloquentBulkDeletes` (+ `deleteIds()`),
  `EloquentColumnTypes`; the entities' shapes (the store's description), the backend and the repositories (the
  persistence's: `EloquentPersistence` registers and remembers itself).
- `Omnibase\Lifecycle\`: `TrasheableListener`, `VersionableListener`, `IntlListener`, `ThreadListener` (with
  `StoreMentionees`), `SettingListener`, `TextOverrideListener`, `EntityDispatcherListener` (doctrine/persistence's
  `LifecycleEventArgs` over `EloquentObjectManager`, `PreUpdateArgs`), `PreFlushListener`, `DemoAccountLockListener`,
  and `OmnibaseListeners::attach()`, which attaches the relay and them in Doctrine's order;
  `EloquentPersistence::create()`'s `lifecycle` option, `lifecycle()`. Not adapted, with the reason, or refused aloud:
  docs/behaviours.md.
- `EloquentUnitOfWork`: `scheduleForUpdate()`, `scheduleForDelete()`, `propertyChanged()`, `setOriginal()`,
  `scheduledCollectionUpdates()`, `scheduleOrphanRemoval()`, `cancelOrphanRemoval()`, `recomputeWithAssociations()`,
  `detach()`, `isUninitializedObject()`; a flush with only a changed collection is not skipped (Doctrine's
  collectionUpdates); a dirty owning many-to-many updates its owner with an empty change set.
- `LazyCollection`: `isDirty()` as PersistentCollection's, an element added before the read kept and put after the
  elements read, an owning side's `clear()` taking its snapshot (`wasCleared()`: the owner's rows all go), `wrap()`.
- `OmnibaseCascades`: a translatable's `translations` (persist, refresh, remove, orphan removal) and a translation's
  `translatable` (persist, refresh), which IntlSubscriber maps on Doctrine.
- `Omnibase\Repository\User\PasskeyRepository` is omnibase's `PasskeyRecords` (webauthn's credential repository:
  `findOneByCredentialId()`, `findAllForUserEntity()`, `saveCredentialRecord()` through the unit of work).
- Tests: `Omnibase\LifecycleTest`; `Harness\WriteParityTest` (the behaviours' rows on both backends in the core's
  harness: 8 scenarios equal); `Site\WriteParityTest` (lesringostars' Article: Countify); `Site\ByIdParityTest` with the
  relay attached (rolesPositions compared, no "postLoad only" left); `Support\OmnibaseHarness`.
  `composer.json` suggests doctrine/persistence and web-auth/webauthn-lib.

### Added: the writes (omnisense/omnibase's plan "omnibase agnostic", step F7c, first part: the unit of work)

- `EloquentUnitOfWork` writes, as Doctrine's unit of work writes (docs/writing.md), and is omnisense/omnibridge's
  `Snapshots`: `persist()` and `remove()` with the declared cascades, `flush()` in Doctrine's order - PreFlush, the
  change sets (strict comparison with the store's snapshots; new entities reached persisted where the association
  cascades, refused otherwise; owning many-to-many collections against their snapshot; orphans removed), OnFlush, then
  in one transaction the inserts in commit order with the identifier the database gives read back (PostPersist after
  all), the updates by class and identifier after PreUpdate (its `setNewValue()` written, the listeners' own changes
  recomputed), the extra updates, the join rows, the deletes in reverse order (PostRemove after all); the snapshots
  taken again, an application's collection becoming a `LazyCollection`; PostFlush. `transactional()`, `changes()`,
  `recompute()`, `original()`; `listen()` and a PSR-14 dispatcher for the Lifecycle events; `scheduledInsertions()`,
  `scheduledUpdates()`, `scheduledDeletions()`, `scheduleExtraUpdate()`, `cancelDeletion()` for OnFlush listeners. A
  failed flush rolls back and closes the unit of work.
- `Persister` (the statements: a joined class's tables, the discriminator, NULL plus an extra update for a target not
  written yet, the join tables, the deletes as Doctrine leaves them to the database), `CommitOrder` (Doctrine's
  topological sort), `Cascades` (cascade and orphanRemoval from the declarations, no ORM loaded; `extend()`),
  `InvalidPersistence`. `UnsupportedPersistence` now names what is refused: another identifier generator, a composite
  identifier or join column, a flush during a flush, a closed unit of work.
- The store remembers what each entity held when read (`original()`, `originalCollections()`, `snapshot()`,
  `setOriginal()`), tells its load listeners of each entity once the read is done, in Doctrine's order of postLoad
  (`onLoad()`), and gives the unit of work `value()`, `setValue()`, `register()`, `forget()`. `LazyCollection` keeps a
  snapshot (`snapshot()`, `takeSnapshot()`, `of()`); `IdentityMap::all()`.
- omnibase's part: the repositories write through the unit of work (`persist()`, `remove()`, `flush()`;
  `ScopedWeeks::save()`/`forget()`, `WeekDays::save()`), `OmnibaseCascades` reads `#[Cascade]` and `#[OrphanRemoval]`,
  `EloquentPersistence` takes a `dispatcher` option.
- Tests: `WriteParityTest` (Doctrine and Eloquent on two SQLite databases: the same rows, the same events), the hours'
  writes in `Omnibase\RepositoriesTest`, `Omnibase\OmnibaseCascadesTest`; the fixtures gain cascades, setters and
  `Setting` (an identifier the application gives).

### Added: the finder language and the core's repositories (omnisense/omnibase's plan "omnibase agnostic", step F7b, second part)

- `Omnibase\Finder\EloquentFinder`: the core's finder `Query` (Base\Database\Repository\Finder\Parser) compiled onto
  Illuminate's query builder as omnibridge/doctrine's ServiceEntityParser compiles it to DQL - every word of a criterion,
  the dates, the discriminator words, an association or a path joined once with the store's conditions (the scopes) and a
  subclass target's discriminator in the join's ON, a Translatable's translations on their own key (TranslatableWalker's
  rewrite), the key without a join, the groups, the requests (find and its specials, count, distinctCount, countFor,
  lengthOf, sumOf/minOf/maxOf, grouped counts), the order (FIELD, RAND, a path, a selected value), limit, offset,
  selectAs, groupBy. `EntitySubject` (the core's `Finder\Subject` from the description: aliases, columns), `FinderQuery`
  (where Doctrine hands back its Query: Doctrine's result shapes, one entity per root row), `Expressions` (the DQL
  fragments a call hands over, translated per database, unknown ones refused), `Conditions`, `Compilation`, `Order`.
  Refused aloud (`UnsupportedQueryException`): MemberOf, Partial on an association or a list, a path through two
  associations, a JSON field through a path, an expression not translated.
- `Omnibase\EloquentEntities` is the base repository: find/findAll/findBy/findOneBy/count/findByExample through the finder,
  `__call()` for the language. Changed: a criteria array is read as omnibase's ServiceEntityRepository reads it - `null`
  compares `= NULL` (no row; the word Null or `Key::of(null)` say IS NULL), an association is joined.
- `Omnibase\Repository\*`: the core's 28 repository interfaces on Eloquent (Threads, Users, ThreadTranslations, Tags,
  Comments, Likes, Mentions, Notifications, Connections, Passkeys, Identities, Tokens, PushSubscriptions, Complaints,
  Settings, Widgets, Slots, ImageCrops, Redirections, Semantics, TrashBalls, Revisions, PageViews, PageVisits, Visits,
  ScopedWeeks, SpecialDays, WeekDays) and TextOverrideRepository's map(), omnibridge/doctrine's methods on the same
  finders; the 14 `#[BackendOnly('doctrine')]` methods refused with `BackendOnly::refuse(..., 'eloquent')`; the writes
  (ScopedWeeks::save/forget, WeekDays::save) with UnsupportedPersistence.
- `EloquentRepositories`: `of()` gives the core's repository for an entity and its subclasses, `register()` an
  application's, `implementing($interface)`, `repositoryClass()`.
- The store: `EntityMap::alias()/column()/idColumns()` take a prefix (a joined class's tables), `hasColumn()`;
  `EntityStore::constrain()` (the conditions on a joined class, into a join's ON) and `results()` (the entities with the
  values selected beside them); a condition receives the prefix (`EloquentBackend`'s scopes in a join's ON).
- Tests: `Omnibase\FinderTest`, `Omnibase\RepositoriesTest`, `Support\FinderParity`; `Harness\FinderParityTest` (in
  omnisense/omnibase's harness, SQLite: 128 finder calls - 113 equal, 11 refused by both, 4 known differences, each with
  its reason - and the 42 rewritten queries equal) and `Site\FinderParityTest` (on lesringostars' MySQL data: the 61
  finder calls, the 51 rewritten methods and Comments' 9 on a trashed thread all equal; 79 words on the site's rows: 74
  equal, 1 in another order (RAND), 3 refused by both, 1 known difference). docs/finder.md.

### Added: the reads (omnisense/omnibase's plan "omnibase agnostic", step F7b, first part)

- The package: omnibase's plain entities read through illuminate/database - Laravel's connection or Capsule - from the
  tables omnisense/omnibridge's `Omnibridge\Schema` describes, no Eloquent model, no Doctrine (a data mapper, the plan's
  answer 1).
- `Connections` (Laravel's connection, Capsule from a configuration or a database URL, a PDO already open),
  `Mapping`/`EntityMap` (tables, columns, identifier and discriminator of each class, joined and single-table
  inheritance), `EntityStore` (the class's query, rows to entities, a subclass's tables, find, findMany, reference,
  select, refresh; a field mapped on a PHP enumeration made one with from()), associations as PHP 8.4 lazy ghosts or read at once and `LazyCollection` (doctrine/collections),
  `IdentityMap`, `Casts` (DBAL's portable types, UTC dates, an application's own types and ColumnTypes),
  `EloquentUnitOfWork` (omnisense/omnibridge's `UnitOfWork`, reading only), `UnsupportedPersistence` and
  `EntityNotFound`.
- `Omnibase\`: `EloquentPersistence` (the core's and the application's entities described by the core's
  `EntitySchema`, the store, unit of work, backend, repositories), `OmnibaseCasts` (UTC datetime, `array`, the passkeys'
  types, the enumerations and sets), `EloquentBackend` (omnisense/omnibridge's `Backend`, the trash and vault scopes),
  `EloquentRepositories` and `EloquentEntities` (the core's `Repositories` and `Entities`, a criteria array as
  Doctrine's `findBy()` reads one).
- Tests: `EntityStoreTest` (fixtures on SQLite, their rows written by Doctrine, read equal by id, laziness, identity,
  scopes, writes refused), `ConnectionsTest`, `Omnibase\OmnibasePartTest` (omnibase's types, the core's repositories on
  the store, the backend's scopes), `Site\ByIdParityTest` (an application's every entity read by id on both backends, its rows
  and a row written for each class it has none of, in a transaction rolled back - on lesringostars: 158 sampled rows
  of 33 classes equal with no scope and with the trash scope, 70 written classes equal, the 10 abstract ones not read);
  the Docker harness (`compose.yml`,
  `compose.checkouts.yml`).
