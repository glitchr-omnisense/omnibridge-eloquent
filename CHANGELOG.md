# Changelog

## [Unreleased]

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
