# omnibridge/eloquent

omnibase's entities on Eloquent's database layer, framework-free: illuminate/database (Laravel's connection, or Capsule
outside Laravel) reading the tables [omnisense/omnibridge](https://github.com/glitchr-omnisense/omnibridge)'s
`Omnibridge\Schema` describes into the same plain PHP entities Doctrine maps - a data mapper, never Eloquent models.

```sh
composer require omnibridge/eloquent
```

- [Reading entities](reading.md): the connection, the store, associations, the identity map, the casts, the unit of
  work.
- [Writing entities](writing.md): the unit of work - persist, remove, flush in Doctrine's order, the cascades, the
  lifecycle events, what is refused.
- [omnibase's persistence](omnibase.md): `EloquentPersistence`, the backend and its scopes, the repositories.
- [omnibase's behaviours](behaviours.md): the attributes' relay, their parts on Eloquent, the listeners' adapters,
  what is refused.
- [The finder language](finder.md): omnibase's finders compiled onto Illuminate's query builder, what is refused, the
  differences with omnibridge/doctrine.
- [Testing](testing.md): the suite, the comparisons with Doctrine (reads by id, the finder parity) in omnisense/omnibase's
  harness and on an application's database.

Steps F7b (the reads) and F7c (the writes, then the behaviours) of omnisense/omnibase's plan "omnibase agnostic".
