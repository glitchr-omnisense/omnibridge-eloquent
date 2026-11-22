# Changelog

## 2.2.0

- **The entities' lifecycle callbacks run on Eloquent** (`#[HasLifecycleCallbacks]` with `#[PrePersist]`,
  `#[PostPersist]`, `#[PreUpdate]`, `#[PostUpdate]`, `#[PreRemove]`, `#[PostRemove]`, `#[PostLoad]`, `#[PreFlush]`), at
  Doctrine's moments: `EloquentUnitOfWork` calls the methods omnisense/omnibridge 2.1's description carries
  (`Schema\Entity::$callbacks`, the parents' first) before the listeners of each event, with the event as their
  argument; a PreUpdate callback's changes are written (recomputed after it, as Doctrine does), and each entity's
  preFlush callbacks run when its change set is computed. Until 2.1 they were read and silently not called
  (omnipack/restaurant's `#[PreUpdate] touch()`). `CallbackParityTest`: the same calls in the same order and the same
  rows on Doctrine and on Eloquent. requires omnisense/omnibridge ^2.1.
- The description's options `packages` and `exclude` (omnisense/omnibase 7.2: the packages discovered, the folders
  left out) are taken by `EloquentPersistence` as the other EntitySchema options are.

## 2.1.0

The dates as omnibridge/doctrine 4.0 reads them: an omnibase site's casts (`Casts::utc()`) read `datetime` and
`datetimetz`, and `utc_datetime` under any casts, as a `\DateTimeImmutable` in UTC (they were `\DateTime`), as they
read `datetime_immutable`, `datetimetz_immutable` and `utc_datetime_immutable`; the parity of the two persistences
holds. Any `\DateTimeInterface` is still written, in UTC. `Casts` without `utc()` reads DBAL's own types as DBAL does
(`datetime` mutable, in PHP's zone); `date` and `time` are unchanged. require-dev: omnibridge/doctrine ^4.0. The test
fixture `Thread::$createdAt` is a `\DateTimeInterface`.

## 2.0.0

omnibridge/eloquent: omnibase's persistence on Eloquent's database layer (illuminate/database), framework-free - a
data mapper reading omnisense/omnibridge's described tables into the same plain PHP entities every persistence maps
(lazy ghosts and collections, an identity map, casts), a unit of work writing the same rows as Doctrine's with the
same neutral lifecycle events, and, behind omnisense/omnibase 7.0's contracts, the engine of its agnostic
repositories: `Finders` (the finder language and `findBy()`'s criteria array compiled onto Illuminate's query builder),
the selection the core's common SQL is written around (findValuesBy(), updateBy(), lockOneBy(), sql()...),
the lifecycle dispatched to omnibase's behaviours, the entity dispatchers and the host's listeners
(`Base\Contract\Lifecycle\Listeners::TAG`). It declares no repository: `Base\Repository\*`, a bundle's and
`App\Repository\*` are the same classes on every persistence.
