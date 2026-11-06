# omnibridge/eloquent

omnibase's entities on Eloquent's database layer, framework-free: illuminate/database (Laravel's connection, or Capsule
outside Laravel) reading the tables [omnisense/omnibridge](https://github.com/glitchr-omnisense/omnibridge)'s
`Omnibridge\Schema` describes into the same plain PHP entities Doctrine maps - a data mapper, never Eloquent models.

```sh
composer require omnibridge/eloquent
```

- [Reading entities](reading.md): the connection, the store, associations, the identity map, the casts, the unit of
  work.
- [omnibase's persistence](omnibase.md): `EloquentPersistence`, the backend and its scopes, the repositories.
- [Testing](testing.md): the suite, the comparison with Doctrine on an application's database.

Step F7b of omnisense/omnibase's plan "omnibase agnostic" (the reads). Writing is step F7c: refused aloud until then
(`UnsupportedPersistence`).
