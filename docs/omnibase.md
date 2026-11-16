---
title: omnibase's persistence
order: 20
---

# omnibase's persistence (`Omnibridge\Eloquent\Omnibase\`)

Loaded with omnisense/omnibase 5.0 (in `suggest`).

```php
use Omnibridge\Eloquent\Connections;
use Omnibridge\Eloquent\Omnibase\EloquentPersistence;

$persistence = EloquentPersistence::create(Connections::url($_ENV['DATABASE_URL']), ['project_dir' => $projectDir]);
$users = $persistence->repositories()->of(App\Entity\User::class);
$user = $users->findOneBy(['email' => 'someone@example.org']);
```

- **The description**: the core's `Base\Database\Schema\EntitySchema` over the core's `src/Entity`, the project's and the
  `directories` given - no Doctrine. Options: `project_dir`, `directories`, and EntitySchema's (`enumerations`, `types`,
  `resolve_target_entities`, `rules`, `column_types`).
- **The casts** (`OmnibaseCasts`): datetime and datetimetz in UTC; `array` unserialized; `passkey_base64` decoded,
  `passkey_aaguid` a string, `passkey_trust_path` JSON; each enumeration and set through its own `toPHPValue()` /
  `toDatabaseValue()` (a set reads NULL as `[]`).
- **The backend** (`EloquentBackend`, omnisense/omnibridge's `Backend`): `name()` "eloquent"; `supports()` lazy
  associations only (no second-level or result cache; transactions with the writes); `enableScope('trash')` adds
  `deletedAt IS NULL` to every query of a `#[Trasheable]` entity, `enableScope('vault', ['environment' => 'prod'])`
  `vault IS NULL OR vault = 'prod'` for a `#[Vault]` one - the core's `TrashScope` and `VaultScope`, what Doctrine's
  filters compile -, in the class's WHERE and in the ON of a join of it (the finder's), as Doctrine's filters.
- **The repositories**: none here. The core's agnostic classes (`Base\Repository\*`, a bundle's, `App\Repository\*`)
  are built over `EloquentFinders` (`finders()`); `repositories()` is the core's `EntityRepositories` over them:
  `of($class)` the entity's repository (else the plain `ServiceEntityRepository`), `implementing($interface)`,
  `byIds()`, `oneById()`, `register()`. Every repository reads the finder language (`findOneByEmailOrUsername()`,
  `countByStateAnd...()`) and `findBy()`'s criteria array as on Doctrine - see [the finder language](finder.md). The
  core's analytics and `Redirections::hit()` are written once in the language, the same rows as on omnibridge/doctrine.

**Writes** ([Writing entities](writing.md)): through the unit of work, the repositories' `persist()`, `remove()` and
`flush()` as `ServiceEntityRepository`'s, `ScopedWeeks::save()`/`forget()` and `WeekDays::save()` as omnibridge/doctrine's.
The cascades include omnibase's `#[Cascade]` and `#[OrphanRemoval]` (`OmnibaseCascades`); `EloquentPersistence`'s option
`dispatcher` (PSR-14) receives the Lifecycle events.
