# Writing

`EloquentUnitOfWork` writes the entities the store reads, as Doctrine's unit of work writes them: the same calls on
the same entities leave the same rows (`WriteParityTest` compares every column of every table, the identifiers the
database gave included) and dispatch the same lifecycle events in the same order. It is omnisense/omnibridge's
`UnitOfWork` and `Snapshots`.

```php
$unitOfWork = new EloquentUnitOfWork($store, $dispatcher);   // a PSR-14 dispatcher, optional
$unitOfWork->listen(fn (object $event) => ...);              // Lifecycle\PrePersist ... PostFlush, PostLoad

$article = $store->find(Article::class, 12);
$article->setTitle('Zanzibar');
$unitOfWork->persist($comment = new Comment($article));
$unitOfWork->flush();
```

## persist(), remove()

- `persist()`: a new entity is scheduled for insertion (`PrePersist`), and so are the new entities its associations
  reach where they cascade the persist. An identifier the application gives (no generated value) must be set by
  then; the entity is kept in the identity map at once. A removed entity persisted again is taken back.
- `remove()`: the entities the associations cascade the removal to first (a collection never read is read), then the
  entity scheduled for deletion (`PreRemove`). A new entity removed is no longer inserted.
- The cascades are the mapping attribute's `cascade` and `orphanRemoval` (`Cascades`, through omnisense/omnibridge's
  declaration reader: no ORM loaded); omnibase's `#[Cascade]` and `#[OrphanRemoval]` are read as well
  (`Omnibase\OmnibaseCascades`, added by `EloquentPersistence`).

## flush()

In Doctrine's order (`UnitOfWork::commit()`):

1. `PreFlush`.
2. The change sets. A new entity: each field (an identifier the database gives left out) and each owning to-one
   association. A managed entity: each value compared with what it held when it was read or last flushed (the store's
   snapshot), strictly - `!==`, an object by identity, an enumeration by its value -, a collection read and put out of
   the entity included (Doctrine signals it there). The associations: a new entity reached is persisted when the
   association cascades the persist (a `PrePersist`), and refused otherwise (`InvalidPersistence::notCascaded()`,
   Doctrine's message); an owning many-to-many collection is compared with its snapshot, and its owner, with no other
   change, is updated with an empty change set (its `PreUpdate`, as Doctrine does); the entities taken out of an
   orphan-removing association (or replaced in an orphan-removing to-one) are removed.
3. `OnFlush`: a listener may persist, remove, change, `recompute()`, or schedule an extra update.
4. In one transaction:
   - the inserts in commit order (`CommitOrder`, Doctrine's topological sort): an entity after the new entities its
     foreign keys point to. A nullable foreign key in a cycle is written NULL, then set by an extra update; a cycle of
     mandatory ones is refused. The root's table first with the discriminator, the identifier the database gives read
     back and set on the entity, then each table a joined class adds. `PostPersist` for each, once all are written;
   - the updates by class and identifier, each after its `PreUpdate`: a value a listener gave with `setNewValue()` is
     written, and the listener's own changes to the entity are recomputed, as Doctrine recomputes after `preUpdate`.
     `PostUpdate` for each;
   - the extra updates;
   - the join tables' rows: those of the targets taken out deleted, those added inserted; a collection put in place of
     the one read replaces all its rows;
   - the deletes, an entity before those its foreign keys point to (but a `SET NULL` one): the join tables' rows of its
     many-to-many associations (but those the database cascades: Doctrine's `isOnDeleteCascade`), then the root
     table's row (the tables a joined class adds go with it by their foreign key). The identifier the database gave is
     set back to null. `PostRemove` for each, once all are deleted.
5. The snapshots taken again: a collection the application made becomes a `LazyCollection` holding the same elements
   (Doctrine puts a `PersistentCollection` there); the deleted entities leave the collections that held them. An
   entity inserted or updated keeps, for its fields and to-one associations, what it held when its change set was
   computed (or recomputed after `PreUpdate`), not what the `PostPersist`/`PostUpdate` listeners left: Doctrine's
   original data. A change those listeners make (or `PostFlush`'s) is written by the next flush, as on Doctrine (seen
   on lesringostars' sign-up: `User::$rolesPositions`, `[0]` when inserted, `[]` once the behaviours ran, is written
   `[]` by the next flush on both). `PostFlush`.

A flush that finds nothing to write dispatches `OnFlush` and `PostFlush` only. A flush that fails is rolled back and
closes the unit of work (`UnsupportedPersistence::closed()`), as Doctrine closes its entity manager.

## The rest of the contract

- `transactional($work)`: the work, then a flush, in one transaction; rolled back (and closed) when it throws.
- `isNew()`, `contains()`, `identifier()`: as Doctrine answers them before and after each step.
- `changes()`: the entity's change set while a flush runs (`[]` outside one); `recompute()`: Doctrine's
  `recomputeSingleEntityChangeSet()` - the first change set of an entity persisted during the flush.
- `original()` (`Snapshots`): the fields and to-one associations as read or last flushed.
- `scheduledInsertions()`, `scheduledUpdates()`, `scheduledDeletions()`, `scheduleExtraUpdate()`,
  `cancelDeletion()`: what a listener of `OnFlush` reads and does on Doctrine's unit of work. Since the behaviours
  (step F7c, second part; [omnibase's behaviours](behaviours.md)): `scheduleForUpdate()` (a forced update, its
  PreUpdate dispatched), `scheduleForDelete()` (no PreRemove, nothing cascaded), `propertyChanged()`, `setOriginal()`,
  `scheduledCollectionUpdates()` (the collections changed, as Doctrine's dirty PersistentCollections),
  `scheduleOrphanRemoval()`, `cancelOrphanRemoval()`, `recomputeWithAssociations()` (Doctrine's `computeChangeSet()`
  on a managed entity), `detach()`, `isUninitializedObject()`.
- `LazyCollection` tells whether it changed since read (`isDirty()`, as PersistentCollection's: an element taken out
  that was not there changes nothing), keeps an element added before it is read and puts it after the elements read
  (no read for it), and on an owning many-to-many's `clear()` takes its snapshot (empty: the owner's rows all go at the
  flush, `wasCleared()`) and is not dirty until an element is added - Doctrine's. A cleared and refilled owning
  collection updates its owner with an empty change set (its PreUpdate runs), as Doctrine's dirty collection does.
  `wrap()` gives the elements, once read, to another collection (omnibase's `OrderedArrayCollection`).
- `PostLoad` for each entity the store reads, once the read is done, in Doctrine's order (after the entities a row
  makes it read at once through an owning association, before an inverse one-to-one read for it).

## Refused aloud

- `UnsupportedPersistence`: an identifier generated otherwise than by the database (AUTO, IDENTITY) or the
  application (NONE) - a sequence, a custom generator; a composite identifier, join column or join table; a flush
  from a listener of a flush; a write after a failed flush; a repository made without a unit of work.
- `InvalidPersistence` (an `InvalidArgumentException`, where Doctrine throws its `ORMInvalidArgumentException`): a new
  entity reached through an association that does not cascade the persist, a cycle of mandatory foreign keys, an
  identifier the application must give and did not, an entity of no described class, a value of the wrong class in
  an association.

## Differences kept

- A collection Doctrine marks dirty and then puts back as it was (add then remove the same element) is compared by its
  elements for its join rows; since step F7c's second part it is dirty as Doctrine's, so its owner gets the same empty
  `PreUpdate`.
- An orphan-removing one-to-many replaced whole: the elements not in the new collection are removed through the unit
  of work (their `PreRemove`), where Doctrine deletes every row of the owner by SQL, those of the new collection too.
