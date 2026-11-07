---
title: The finder language
order: 30
---

# The finder language on Eloquent (`Omnibridge\Eloquent\Omnibase\Finder\`)

omnibase's finder language - `findOneByEmailOrUsername()`, `cacheOneByStateAndSlugStartingWith()`,
`countForParentByState()`, `lengthOfTagsBy()`, `sumOfViewsByDateGreaterEqualTo()`... - is the core's: its
`Base\Database\Repository\Finder\Parser` reads a method's name and arguments into a neutral `Query`. A persistence only
compiles that query. `EloquentFinder` compiles it onto Illuminate's query builder, as omnibridge/doctrine's
`ServiceEntityParser` compiles it to DQL, so that one name gives the same rows on both. `FinderParityTest` compares the
two on the same database.

```php
$threads = $persistence->repositories()->of(App\Entity\Article\Article::class);   // ThreadRepository's methods
$threads->findByStateAndPublishedAtYoungerThan([ThreadState::PUBLISH], 7 * 86400)->getResult();
$threads->cacheOneBySlug('ruban-rose');
$threads->countByParentAndStateAndWorkflow([12], [ThreadState::PUBLISH], WorkflowState::APPROVED, [], '', null, ['parent']);
$threads->findBy(['parent' => Key::of($thread), Group::by('SlugStartingWithOrSlugStartingWith', 'a', 'b')], ['publishedAt' => 'DESC'], 10);
```

## What is compiled

| The language | On Illuminate's builder |
|---|---|
| EqualTo, NotEqualTo, GreaterThan, GreaterEqualTo, LowerThan, LowerEqualTo | `x = ?`, `<>`, `>`, `>=`, `<`, `<=`. A list gives `IN` or `NOT IN`, and an empty list adds no condition. `null` in a criteria array gives `x = NULL`, as Doctrine's `e.x = :x` does: no row meets it (use the word `Null`, or `Key::of(null)`). |
| Null, NotNull, Empty, NotEmpty, True, False and their Not | `IS NULL`, `IS NOT NULL`, `(x IS NULL OR x = '')`, `(x IS NOT NULL AND x <> '')`, `= 1`, `<> 1` |
| YoungerThan, YoungerEqualTo, OlderThan, OlderEqualTo, Within, IsOver, IsNotOver | The same bound as Doctrine's. An age ("+2 days", or seconds) counts back from now, Within's offset counts forward, both rounded to the minute. A date given as text is read as one. IsOver compares with now. The bound is written with the field's type (UTC datetime, date...). |
| StartingWith, EndingWith and their Not; Partial; Insensitive | `LIKE` / `NOT LIKE`, the underscore escaped as Doctrine escapes it; Partial `LIKE` as given; Insensitive `LOWER(x)` |
| ClosestTo, FarestTo | `ORDER BY ABS(x - ?)` ascending or descending, after the call's order. The value may name a selected expression (`ratio0`). |
| InstanceOf, ClassOf and their Not (`^` reverses one) | The discriminator among the hierarchy map's values: the class and its subclasses, or the class itself. No condition without a hierarchy. |
| But, WithRoute, Model, Eagerly, cache... | `id <> ?`; a route parameter (Symfony's request or kernel event, Laravel's request, an array); a Query-by-Example's set fields; Eagerly and cache change no row (the store reads lazily and keeps no result cache) |
| An association, or a path through one (`translatable.state`, `tags.slug`) | A `LEFT JOIN` of it, once. A to-one is joined on its foreign key, a one-to-many or an inverse one-to-one on the target's key, a many-to-many through its join table. The store's conditions (the trash and vault scopes) go into the join's `ON`, and so does a subclass target's discriminator, as Doctrine's SQL filters do. A Translatable's `translations` are joined on the translation's own `translatable_id`, in the root table of its hierarchy (what `TranslatableWalker` rewrites on Doctrine). |
| `Key::of($entity \| $id \| list \| null)` | The row's own foreign key, with no join (`= ?`, `IN`, `IS NULL`) |
| `Group::by('AOrB', ...)` | Its members in parentheses, joined by its separator |
| find, One, All, Randomly, AtMost<N>, Last<N>, LastOne, Previous<N>, PreviousOne, Next<N>, NextOne | The same internal requests and arguments as `ServiceEntityParser`'s (a call Doctrine refuses with a `TypeError` is refused the same way) |
| count, distinctCount, countFor<Column> | `COUNT(x)`, `COUNT(DISTINCT x)`. Grouped (groupBy or selectAs), the rows `['entity' => ..., <group> => ..., 'count' => ...]` |
| lengthOf<Column> | `LENGTH(x)`, or a collection's size (a subquery counting its join table or target rows) |
| sumOf, minOf, maxOf | `SUM()`, `MIN()`, `MAX()` of a field: the database's value, null without a row |
| Order, limit, offset | A field, or an association's field (joined). A list of values gives `FIELD()` on MySQL and the same `CASE` elsewhere, bound. `id => 'rand'` gives `RAND()` (`RANDOM()` on SQLite), the terms before it dropped. A selected expression's name, or an expression (below). An offset alone gets MySQL's largest limit, as Doctrine's platform writes it. |
| selectAs, groupBy | `Expressions`: `e.<field>`, `e_<association>.<field>`, numbers, quoted texts, `+ - * / %`, parentheses, ABS, LOWER, UPPER, LENGTH, COALESCE, MOD, ROUND, RAND(), FIELD(), JSON_ARRAY_POSITION() (MySQL's JSON_TABLE as omnibridge/doctrine writes it, json_each() on SQLite) |
| A JSON field | Each value of a list `JSON_CONTAINS()`ed (AND; NOT for NotEqualTo; LOWER for Insensitive), as Doctrine does; `json_each()` on SQLite |

A finder that hands back Doctrine's `Query` hands back a `FinderQuery`: `getResult()`, `getOneOrNullResult()`,
`getSQL()`, `getParameters()`, iterable. The results have Doctrine's shapes. There is one entity per root row, at its
first row, as Doctrine's `ObjectHydrator` keeps it: a join of a to-many association repeats no entity, and the LIMIT
counts SQL rows on both. A mixed row is `[0 => entity, alias => value]`, or `['entity' => ...]` when grouped, with the
values as the database gives them, in the order Doctrine selects them.

## What is refused aloud (`UnsupportedQueryException`)

- **MemberOf**: omnibridge/doctrine writes `e MEMBER OF <class>`, which DQL refuses too.
- **A Partial criterion on an association, or Partial on a list**: Doctrine binds it under a name its expression does
  not use, and refuses the query.
- **A path through more than one association**, a field the entity does not have, an order on an association itself.
- **A JSON field compared through a path**, or Insensitive on one JSON value.
- **An expression `Expressions` does not translate**: a parameter, another function, a name that is no field.

The 14 methods declared `#[BackendOnly(['doctrine', 'eloquent'])]` on the core's interfaces are no finder: the
analytics' upserts, `INSERT IGNORE` and breakdowns, `Redirections::hit()`'s bulk UPDATE. Each persistence writes them;
here they are omnibridge/doctrine's statements sent through Illuminate's connection (`Repository\Analytics\Statements`:
the table wrapped by the grammar, positional bindings, MySQL's or SQLite's form by the driver), and `hit()` one
`UPDATE ... SET hits = hits + 1` on the row - the same rows (tests/Harness and tests/Site `BackendWrittenParityTest`).

What Doctrine refuses with its own message (an AND/OR ambiguity, an operator a list does not take, an Older/Younger
date) is refused with the same message.

## The repositories

`EloquentEntities` is the base of every repository on the store. It provides `find()`, `findAll()`, `findBy()`,
`findOneBy()`, `count()` and `findByExample()` through the finder, and `__call()` for the language.
`EloquentRepositories::of($class)` gives the core's repository on Eloquent for its entity and the entity's subclasses
(`Repository\*`, one per interface of `Base\Repository\Aliases::INTERFACES`: `Threads`, `Users`, `Comments`, `Tags`,
`ThreadTranslations`, the analytics, the hours...). An application registers its own with `register()`, and any other
class gets the plain `EloquentEntities`; `register($entity, $class, $factory)` makes it with a factory when it needs more
than the store (lesringostars' comments' counts take a cache). `implementing($interface)` gives the repository of a
core interface: the one that declares it before a registered subclass that only inherits it (an application's
`ArticleRepository`, an `Articles` and so a `Threads`, does not answer `Threads`). Writes
(`persist`, `remove`, `flush`, `ScopedWeeks::save()`...) go through the unit of work ([Writing entities](writing.md)).

## Known differences with omnibridge/doctrine

In each of these cases Doctrine refuses something Eloquent compiles. The parity tests record them as known
differences, each with its reason:

- **An order on a list of texts**: Doctrine writes `FIELD(e.state,STATE_DRAFT,STATE_PUBLISH)` with the values unquoted,
  and DQL reads them as names (a semantical error).
- **RAND() on SQLite**: omnibridge/doctrine's `Rand` writes `RAND()`, which SQLite does not have. On MySQL both write
  `RAND()`.
- **Eagerly on `Thread::$tags`** (in omnisense/omnibase's harness): Doctrine's fetch join of the cached tags leaves the
  collection unset (`Thread::getTags()` returns null: a TypeError).
