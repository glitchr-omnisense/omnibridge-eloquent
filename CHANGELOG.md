# Changelog

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
