# Changelog

## [Unreleased]

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
