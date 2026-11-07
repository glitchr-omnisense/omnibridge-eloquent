# omnibridge/eloquent

omnibase's entities on Eloquent's database layer, framework-free: illuminate/database - Laravel's
connection, or Capsule outside Laravel - reading the tables omnisense/omnibridge's `Omnibridge\Schema`
describes into **the same plain PHP entities Doctrine maps**. A data mapper (omnisense/omnibase's plan
"omnibase agnostic", answer 1): the entities are never turned into Eloquent models, nothing in them
knows which backend read them, and an application may keep its own Eloquent models beside them.

Steps F7b (**the reads**) and F7c (**the writes**) of that plan: the unit of work persists, removes and
flushes as Doctrine's does - the same rows, the same lifecycle events in the same order - and refuses aloud
what it does not do (`UnsupportedPersistence`).

```sh
composer require omnibridge/eloquent
```

## The store (`Omnibridge\Eloquent\`)

- `Connections`: the connection, whoever makes it - `laravel(app('db'), $name)` inside Laravel,
  `capsule($config)` or `url('mysql://user:password@host/name')` outside it, `pdo($pdo)` on a PDO that is
  already open (Doctrine's: the same database and the same transaction, what comparing two backends needs).
- `Mapping` / `EntityMap`: where an entity's values are, from its description - the tables of its class
  (joined inheritance: the root's to the class's, joined on the identifier; single-table: the root's, with
  the class's discriminator values), each field's and owning association's table and column, the
  identifier, the class a row is (the discriminator map). A class is asked by any of its names, a class
  alias included (omnibase's `App\Entity\X` for `Base\Entity\X`).
- `EntityStore`: rows read into entities - the class's query (every column of its tables, aliased; the
  store's conditions), a subclass's own tables read by identifier, the entity made without its
  constructor and its properties written raw, as Doctrine writes them; `find()`, `findMany()` (in the
  order given), `reference()`, `select()` (a constraint on Illuminate's query builder, the finder
  compiler's entry), `refresh()`, `identifier()`.
- Associations as Doctrine gives them: a to-one association is the entity already read, else a **PHP 8.4
  lazy ghost** of its class with its identifier set (reading the identifier reads no row; `EntityNotFound`
  when the row is gone), else - a target with subclasses, whose class only its row says, or the inverse side
  of a one-to-one - the entity read at once; a to-many association is a `LazyCollection`
  (doctrine/collections' `Collection`, read on first use), ordered and indexed as declared, owning or
  inverse, one-to-many or through its join table.
- `IdentityMap`: one object per row, by root class and identifier.
- `Casts`: a column's value as the entity holds it, and back, by the type's name - DBAL's portable types
  (dates in PHP's zone, or in UTC with `Casts::utc()` as omnibridge/doctrine's `Types::register()` reads
  them; JSON; simple_array; bigint...), an application's own types given with `with()` or read from its
  `ColumnTypes` (an alias followed, an enumeration's value, a set's values). A type nothing knows is
  refused.
- `EloquentUnitOfWork`: omnisense/omnibridge's `UnitOfWork` and `Snapshots`, writing as Doctrine's unit of
  work writes (step F7c, [docs/writing.md](docs/writing.md)): persist and remove with the declared cascades
  (`Cascades`), flush in Doctrine's order - change sets against the store's snapshots, orphan removal, inserts
  in commit order (`CommitOrder`; a nullable foreign key in a cycle by an extra update), the identifier the
  database gives read back, updates after `PreUpdate` (its `setNewValue()`), join rows, deletes in reverse
  order (`Persister`) -, the Lifecycle events to listeners and a PSR-14 dispatcher (`PostLoad` from the
  store), transactional, changes, recompute. Refused aloud: `UnsupportedPersistence`, `InvalidPersistence`.

```php
use Omnibridge\Eloquent\{Casts, Connections, EntityStore, Mapping};

$store = new EntityStore(Connections::url($_ENV['DATABASE_URL']), new Mapping($description), Casts::utc($columnTypes));
$article = $store->find(Article::class, 12);    // an Article, or its subclass's row
$article->getAuthor()->getId();                  // a ghost: no query
$article->getTags()->count();                    // the collection read now
```

## omnibase's persistence on Eloquent (`Omnibridge\Eloquent\Omnibase\`)

Loaded with omnisense/omnibase (`suggest`): `EloquentPersistence::create($connection, ['project_dir' => ...])`
describes the core's and the application's entities (the core's `EntitySchema`, no other persistence asked)
and gives:

- the store with omnibase's column types (`OmnibaseCasts`: datetime in UTC, `array` serialized, the passkeys'
  base64 / AAGUID / trust path, each enumeration and set through its own `toPHPValue()`);
- `EloquentBackend`: omnisense/omnibridge's `Backend` ("eloquent", lazy associations) with omnibase's trash and
  vault scopes added to every query while enabled, as Doctrine's filters are;
- `EloquentRepositories`: the core's `Repositories` - `of()` gives the core's repository on Eloquent for its entity and
  its subclasses (one per interface of the core: `Threads`, `Users`, `Comments`...), an application's own
  (`register()`), else the plain `EloquentEntities`; `implementing($interface)`, `byIds()`, `oneById()`;
- **the finder language** (`EloquentFinder`): omnibase's finders - `findOneByEmailOrUsername()`,
  `cacheOneByStateAndSlugStartingWith()`, `countForParentByState()`, `sumOfViewsBy()`... - read by the core's
  `Finder\Parser` and compiled onto Illuminate's query builder word for word as omnibridge/doctrine compiles them to DQL
  (the joins with the scopes in their ON, the Translatable join, the key, the groups, FIELD/RAND/JSON_ARRAY_POSITION,
  the grouped counts and their rows); what it cannot express is refused aloud (`UnsupportedQueryException`). The 14
  `#[BackendOnly]` methods (the analytics' native SQL, `Redirections::hit()`) are written on Eloquent's connection.
  See [docs/finder.md](docs/finder.md).

## Tests

```sh
docker compose run --rm test                                          # the published packages
docker compose -f compose.yml -f compose.checkouts.yml run --rm test  # the family's checkouts beside this one
```

`EntityStoreTest`: fixture entities (a joined and a single-table hierarchy, every kind of association, the
portable types) on SQLite in memory, their tables created from the description with no ORM and their rows
written by Doctrine: each entity read by id is the one Doctrine reads, once normalised (`Support\Normaliser`);
ghosts and collections read nothing until used; one object per row; scopes. `WriteParityTest`: the same writes
on Doctrine and on the unit of work, two SQLite databases, the same rows (every column, the generated identifiers
included) and the same lifecycle events in the same order - a graph inserted in commit order, updates and join
rows, deletes cascaded, orphans, a nullable cycle, a listener's new value and OnFlush insertion, refusals, a
rolled-back transaction, the states. `ConnectionsTest`
(URL, Capsule, PDO, a Laravel database manager) and `Omnibase\OmnibasePartTest` (omnibase's column types, the core's
repositories on the store, the backend's scopes; the core needed: `compose.checkouts.yml` until the core's 5.x with
`EntitySchema` is served).

`Omnibase\FinderTest` (the finder language on the fixtures), `Omnibase\RepositoriesTest` (the core's 28 interfaces
implemented, the 14 backend-only methods refused, the hours' writes through the unit of work) and
`Omnibase\OmnibaseCascadesTest` (omnibase's #[Cascade] and #[OrphanRemoval]).

`Harness\FinderParityTest` (in omnisense/omnibase's harness, SQLite) and `Site\FinderParityTest` (in an application,
MySQL) run every finder call on both compilers, the same database, and compare the rows - see
[docs/testing.md](docs/testing.md). `Site\ByIdParityTest` runs in an omnibase application's Symfony test kernel, the package copied into its
vendor (`tests/Site/autoload.php` appends its class loader): every entity of the application read by id through
Doctrine and through the store on the same connection, on a sample of the site's rows (with and without the
trash scope) and on a row written for each class in a transaction rolled back - see [docs/testing.md](docs/testing.md).

Requires PHP 8.4 (lazy objects), illuminate/database 12 or 13, doctrine/collections 2 or 3, omnisense/omnibridge 1.
