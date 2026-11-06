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
| `EntityStoreTest` | the fixtures (`tests/Fixtures/Entity`: a joined hierarchy Thread/Article/Page, a single-table one Animal/Cat/Dog, many-to-one, inverse one-to-one, one-to-many ordered and indexed, many-to-many owning and inverse; string, text, integer, bigint, boolean, float, decimal, json, simple_array, datetime, datetime_immutable, date) on SQLite in memory, their tables created from the description by omnisense/omnibridge's `SchemaCreator` and their rows written by Doctrine: every entity read by id - by its class and by its hierarchy's root - equal to Doctrine's once normalised; a to-one association a ghost until a field is read (its identifier read without a query), read at once when its target has subclasses; collections read on first use, ordered, indexed; a single-table class reading only its rows; `findMany()`'s order; a missing row null, a ghost of it `EntityNotFound`; the unit of work reading and refusing every write; a condition on every query |

| `ConnectionsTest` | a database URL read as Illuminate's configuration; Capsule's connection, a connection on an open PDO (the same database), a Laravel database manager's (Capsule's manager answers the same `ConnectionResolverInterface` as `app('db')`) |
| `Omnibase\OmnibasePartTest` | with the core installed: omnibase's column types (`array`, the passkeys', UTC datetime, an enumeration); the core's `Repositories`/`Entities` on the store (a criteria array: a field, an association by entity or identifier, IN, IS NULL, order, limit; `byIds()` in order; writes refused); the backend's name, features and scopes |

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
- A difference only a behaviour's postLoad hook makes on Doctrine's side is reported apart (`postLoad only`), not as
  unequal: omnibase's `#[OrderColumn]` writes its positions back as JSON text after Doctrine decoded them; the store
  runs no lifecycle hook before step F7c (the writes and the lifecycle).
- `OMNIBRIDGE_ELOQUENT_TRACE=1` writes where a long run is to `var/eloquent-by-id.trace`.

Made-up values in a thread's row send Doctrine's own read into an endless loop (seen on lesringostars, not
investigated further: Doctrine's side, the row is the test's): that is why rows are copied from real ones where they
exist and the made-up values stay plain.
