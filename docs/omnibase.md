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
  filters compile.
- **The repositories** (`EloquentRepositories`, the core's `Repositories`): `of($class)` an `EloquentEntities` (find,
  findAll, findBy/findOneBy/count on a criteria array - a field or a to-one association equal to a value, an entity
  or its identifier, IN a list, IS NULL - with an order); `byIds()`, `oneById()`.

**Not yet** (step F7b, second part): the finder language (`findOneByEmailOrUsername()`, `countForAllCommentsIn()`...)
compiled onto Illuminate's query builder, the core's repository interfaces (Users, Comments...), the 14
`#[BackendOnly('doctrine')]` methods refused with `UnsupportedQueryException`. **Writes**: step F7c.
