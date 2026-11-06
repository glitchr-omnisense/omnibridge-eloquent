---
title: Reading entities
order: 10
---

# Reading entities

## The connection

`Omnibridge\Eloquent\Connections` gives an `Illuminate\Database\Connection`, with or without Laravel:

| Where | Call |
|---|---|
| Laravel | `Connections::laravel(app('db'), 'mysql')` (the default connection without a name) |
| plain PHP | `Connections::capsule(['driver' => 'mysql', 'host' => ..., 'database' => ...])`, `Connections::url('mysql://user:password@host:3306/name?charset=utf8mb4')`, `Connections::url('sqlite:///var/data.db')` |
| beside Doctrine | `Connections::pdo($entityManager->getConnection()->getNativeConnection(), $database)`: the same PDO, so the same transaction |

## The store

```php
use Omnibridge\Eloquent\{Casts, EntityStore, Mapping};

$store = new EntityStore($connection, new Mapping($description), Casts::utc($columnTypes));
```

- `find($class, $id)`: the entity, or null. A class with subclasses gives the row's own class (its discriminator).
  The same row read twice is the same object (`IdentityMap`), and a row read already costs no query.
- `findMany($class, $ids)`: in the order of the identifiers, the missing ones left out; one query for the rows not read yet.
- `reference($class, $id)`: a lazy ghost, its identifier set, its row read on the first other field asked
  (`EntityNotFound` if there is none); a class with subclasses is read at once (null without a row), as Doctrine does.
- `select($class, fn (Builder $query, EntityMap $map) => ...)`: the rows a constraint on Illuminate's query builder keeps,
  in its order. The class's tables are aliased `t0` (the root's), `t1`...; `$map->column('title')` is `t0.title`.
- `refresh($entity)`, `identifier($entity)`, `isUninitialized($entity)`, `clear()`.
- `addCondition($name, fn (EntityMap $map, Builder $query) => ...)`: a condition on every query while it is set (a
  backend's scope), `removeCondition($name)`.

## Associations

| Association | What the property holds |
|---|---|
| to-one, owning, target without subclasses | the entity read already, else a PHP 8.4 lazy ghost of the target's class (`newLazyGhost`), its identifier set |
| to-one, owning, target with subclasses | the entity read at once (only its row says its class) |
| one-to-one, inverse side | the entity read at once (only a row says whether there is one), or null |
| one-to-many, many-to-many (owning or inverse) | a `LazyCollection` (doctrine/collections' `Collection`), read on first use, in the declared order (`orderBy`), keyed by the declared field (`indexBy`) |

The entities are made without their constructor and their properties written raw
(`ReflectionProperty::setRawValueWithoutLazyInitialization()`), no hook, no setter: what Doctrine does.

## The casts

`Casts` converts a column's value by the type's name a description gives:

- DBAL's portable types: integer, smallint, bigint (a string past `PHP_INT_MAX`), boolean, float, decimal (a string),
  string, text, ascii_string, guid, json, simple_array (NULL as `[]`), datetime, datetimetz, date, time and their
  `_immutable`, binary, blob;
- `Casts::utc()`: datetime and datetimetz in UTC, utc_datetime and utc_datetime_immutable (omnibridge/doctrine's
  `Types::register()`);
- `->with('money', $toPhp, $toDatabase)`: an application's own type;
- the `ColumnTypes` given: an alias followed, an enumeration's value as its string, a set's values as a list.

A type nothing knows is refused (`DomainException`), never read as a raw string.

## The unit of work

`EloquentUnitOfWork` answers omnisense/omnibridge's `UnitOfWork` for what reads: `contains()`, `reference()`, `isNew()`,
`identifier()`, `className()` (a ghost is of its own class), `refresh()`; and writes ([Writing entities](writing.md)).
The store remembers what each entity held when it was read (`original()`, `originalCollections()`: what a flush
compares with) and tells its load listeners of each entity read, once the read is done (`onLoad()`: the unit of
work's `PostLoad`).
