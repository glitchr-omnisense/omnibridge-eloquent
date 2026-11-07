---
title: Testing
order: 60
---

# Testing

## The package's suite

A Docker harness of its own (PHP 8.4 with SQLite, intl, bcmath): `compose.yml` installs the packages, PHPUnit runs.
omnisense/omnibase and omnibridge/doctrine are in `require-dev` - the core's entities, and Doctrine to compare with.

```sh
docker compose run --rm test                                          # the published packages
docker compose -f compose.yml -f compose.checkouts.yml run --rm test  # the family's checkouts mounted over them
```

| Test class | What it checks |
|---|---|
| `EntityStoreTest` | the fixtures (`tests/Fixtures/Entity`: a joined hierarchy Thread/Article/Page, a single-table one Animal/Cat/Dog, many-to-one, inverse one-to-one, one-to-many ordered and indexed, many-to-many owning and inverse; string, text, integer, bigint, boolean, float, decimal, json, simple_array, datetime, datetime_immutable, date) on SQLite in memory, their tables created from the description by omnisense/omnibridge's `SchemaCreator` and their rows written by Doctrine: every entity read by id - by its class and by its hierarchy's root - equal to Doctrine's once normalised; a to-one association a ghost until a field is read (its identifier read without a query), read at once when its target has subclasses; collections read on first use, ordered, indexed; a single-table class reading only its rows; `findMany()`'s order; a missing row null, a ghost of it `EntityNotFound`; the unit of work answering what it read; a condition on every query |
| `WriteParityTest` | the writes on both backends: the same calls on the fixtures (with an identifier the application gives, `Setting`, and cascades: Thread's translations persist/remove/orphanRemoval, User's profile persist) through Doctrine's entity manager on one SQLite database and through `EloquentUnitOfWork` on another, both created from the description: every column of every table equal (the generated identifiers included: the same insert order), and the same lifecycle events in the same order (each one's entity, a PreUpdate's changed fields). Scenarios: a graph inserted in commit order (joined and single-table hierarchies, discriminators, join rows, mandatory and nullable foreign keys, cascades through a one-to-many and an inverse one-to-one); fields, to-one associations, join rows added, taken out and a collection replaced, an orphan removed, a second flush writing nothing; deletes cascaded and ordered, a removed entity persisted again; a nullable cycle broken by an extra update; a listener's `setNewValue()` and an OnFlush insertion; a new entity reached without cascade refused by both; a transaction rolled back; isNew/contains/identifier along the way. Plus: snapshots and changes during a flush, a collection the application made becoming a LazyCollection, a failed flush closing the unit of work |

| `ConnectionsTest` | a database URL read as Illuminate's configuration; Capsule's connection, a connection on an open PDO (the same database), a Laravel database manager's (Capsule's manager answers the same `ConnectionResolverInterface` as `app('db')`) |
| `Omnibase\OmnibasePartTest` | with the core installed: omnibase's column types (`array`, the passkeys', UTC datetime, an enumeration); the core's `Repositories`/`Entities` on the store (a criteria array through the finder: a field, an association by entity or identifier, IN, `= NULL` as omnibase's finder compares it, order, limit; `byIds()` in order; a repository made without a unit of work refusing to write); the backend's name, features and scopes |
| `Omnibase\FinderTest` | the finder language compiled by `EloquentFinder` on the fixtures: each word of a criterion, the associations joined (to-one, many-to-many, one-to-many, a path) and the key not, the discriminator words, the groups and separators, the requests and their shapes (a FinderQuery, Last/AtMost/Next/Previous, counts, a grouped count's rows, lengths, sumOf/minOf/maxOf, a selected value), the orders (FIELD, a path, RANDOM, limit and offset), what is refused aloud |
| `Omnibase\RepositoriesTest` | the core's entities described with no Doctrine: each of the 28 repository interfaces of `Aliases::INTERFACES` implemented on the store, a subclass given its parent's repository, a registered one; the 14 `#[BackendOnly('doctrine')]` methods refused with `UnsupportedQueryException`; ScopedWeeks::save()/forget() and WeekDays::save() through the unit of work, read back |
| `Omnibase\OmnibaseCascadesTest` | omnibase's `#[Cascade]` and `#[OrphanRemoval]` on a property and on its class, over the mapping's, a parent keeping its own |

`tests/Support/Normaliser.php`: an entity as plain data - its class, its mapped fields (a date as its class, time and
zone), its to-one associations as class and identifier (read without loading them), its to-many associations as the
list of them, sorted unless the association declares an order, keyed when it is indexed.

## Comparing with Doctrine on an application's database

`tests/Site/ByIdParityTest.php` runs in an omnibase application's Symfony test kernel. The package is copied into the
application's vendor with its own `vendor/` (nothing of the application's lock changes); `tests/Site/autoload.php`
appends a class loader after the application's, so a class the application has stays the application's.

```sh
rsync -a --exclude=.git omnibridge/eloquent/ app/vendor/omnibridge/eloquent/
OMNIBRIDGE_ELOQUENT_SITE=1 php bin/phpunit vendor/omnibridge/eloquent/tests/Site/ByIdParityTest.php
```

- `testEveryEntityReadByIdIsDoctrinesOnTheSitesData`: for each entity class the core's `EntitySchema` describes, a
  sample of the rows exactly of that class (`OMNIBRIDGE_ELOQUENT_SAMPLE`, 5), read by Doctrine's entity manager and by
  the store on its PDO, compared normalised - with every scope off, then with the trash scope on both sides. Read only.
  Report: `var/eloquent-by-id.json`.
- `testEveryEntityClassReadByIdOnARowWrittenForIt`: the classes the site's data holds no row of - in one transaction,
  rolled back, a row written for each class that is not abstract, under a negative identifier (so no auto-increment
  counter moves): each column a value of its type (a nullable text left null, an enumeration a value the site's rows
  hold, a PHP enumeration its first case, JSON an empty list...), each nullable association null, each required one
  pointing at a row of its target (the site's, else one written before it, so no cycle); a unique join column gets a
  target row of its own, a copy of a real row when the class has one; each many-to-many holding a row; foreign keys
  unchecked for the session. Each row read by id on both sides. Report: `var/eloquent-by-id-written.json`.
- Since step F7c's second part the store runs the behaviours' PostLoad (the relay, `lifecycle` option): a difference
  only a postLoad hook makes (omnibase's `#[OrderColumn]` writing User::$rolesPositions back) counts as a difference;
  the report's `postLoad only` stays empty, and users are asserted compared.
- `OMNIBRIDGE_ELOQUENT_TRACE=1` writes where a long run is to `var/eloquent-by-id.trace`.

### The finder parity (`tests/Site/FinderParityTest.php`)

In the same application kernel: each finder call on omnibridge/doctrine's repositories and on omnibridge/eloquent's, the
same MySQL database and transaction (rolled back), compared normalised (`Support\FinderParity`: entities by class and id,
in order; a query with no ORDER BY may give the same rows in another order; both refusing; a known difference with its
reason; anything else unequal, which fails the test):

- the application's FinderParityTest calls (on lesringostars: its own and the core's on its path, one of each word: 61);
  a method of the application's own repositories is called on Doctrine as written and on Eloquent as the finder it is
  written on (the application's repositories on Eloquent are step F7f);
- the rewritten queries of step E2 (its `App\Tests\Repository\HandWrittenQueries`' "after" methods: 48, and the
  application's 3);
- Comments' findVisible()/countVisible() on comments written under a thread put in the trash (9);
- one of each word and of what the compiler adds - paths through associations and translations (the Translatable join),
  the key, groups, the aggregates, grouped counts and lengths, FIELD(), RAND() - on the application's rows (read only).

```sh
OMNIBRIDGE_ELOQUENT_SITE=1 php bin/phpunit vendor/omnibridge/eloquent/tests/Site/FinderParityTest.php
```

Report: `var/eloquent-finder-parity-site.json`.

## The finder parity in omnisense/omnibase's harness (`tests/Harness/FinderParityTest.php`)

The core's harness (its demo application: the core's, the demo's and the plugins' entities, Doctrine on SQLite), the
package mounted at `/srv/omnibridge/eloquent` with its own `vendor/` (an extra compose file):

```yaml
services:
  omnibase:
    volumes:
      - ../../omnibridge/eloquent:/srv/omnibridge/eloquent:ro
```

```sh
docker compose -f compose.yml -f compose.checkouts.yml -f compose.eloquent.yml run --rm omnibase \
  test --filter FinderParityTest /srv/omnibridge/eloquent/tests/Harness/FinderParityTest.php
```

On rows it writes in a transaction rolled back (threads with states, dates, parents, tags, one in the trash): the core's
FinderParityTest scenarios and one of each word (128 calls), then the core's HandWrittenQueries' "after" methods on
both (42, with the rows the core's own test writes). The harness has no MySQL driver: MySQL is compared on the
application. Report: `var/eloquent-finder-parity-harness.json`.

Made-up values in a thread's row send Doctrine's own read into an endless loop (seen on lesringostars, not
investigated further: Doctrine's side, the row is the test's): that is why rows are copied from real ones where they
exist and the made-up values stay plain.

## omnibase's behaviours on both backends (step F7c, second part)

- `tests/Omnibase/LifecycleTest`: what needs no host - a Doctrine-typed hook and an `EntityExtension` refused aloud,
  `LazyCollection`'s changes as PersistentCollection's (dirty, added before read, an owning side cleared), `wrap()`.
- `tests/Harness/WriteParityTest`, in omnisense/omnibase's harness (SQLite; the package mounted by the core's
  `docker/compose.eloquent.yml`): each scenario through Doctrine's unit of work in a transaction rolled back, then
  through this package's with omnibase's lifecycle attached (`tests/Support/OmnibaseHarness`, from the harness's
  container), every column of every table it touches compared - identifiers as given, dates relative to the start,
  what is random by its shape, a password hash by the password it verifies, a sealed value by what it opens to, an
  upload by its content. Timestamp, Blameable and the trash; Slugify and GenerateUuid; Randomize and Cache;
  OrderColumn and the revisions; Hashify; Vault; the passkeys' records; Uploader (its files removed after: a scratch
  directory). Report: `var/eloquent-write-parity-harness.json`.

  ```sh
  docker compose -f compose.yml -f compose.checkouts.yml -f compose.eloquent.yml run --rm omnibase \
      test --filter WriteParityTest /srv/omnibridge/eloquent/tests/Harness/WriteParityTest.php
  ```
- The core's behaviour tests run on both backends with the same overlay (`Tests\Base\Database\Backend\BothBackends`:
  OrderColumnLazyTest, SlugifyPerTypeTest, UploaderSplFileInfoTest, EmptyTrashTest, ThreadParticipantsTest,
  BehaviourBackendsTest).
- `tests/Site/WriteParityTest`, on an application's database (lesringostars): an Article and its translation written on
  both (Countify, Slugify, GenerateUuid, Timestamp, a revision), in transactions rolled back, the new rows compared with
  their identifiers numbered per hierarchy. `tests/Site/ByIdParityTest` reads with the relay attached: the behaviours'
  PostLoad runs on both sides (User::$rolesPositions included, asserted).

