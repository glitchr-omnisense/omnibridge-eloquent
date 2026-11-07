<?php

namespace Omnibridge\Eloquent;

use Doctrine\Common\Collections\AbstractLazyCollection;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * A to-many association read when it is first used: doctrine/collections' Collection, which the entities
 * type their associations on (ArrayCollection when they make them, Doctrine's PersistentCollection when
 * Doctrine reads them), loaded by the store on the first call that needs its elements.
 *
 * It remembers the elements it held when it was read or last flushed (snapshot()), as Doctrine's
 * PersistentCollection does: the unit of work compares them with what it holds now, for the rows of a join
 * table and for orphan removal. A collection never read holds what its snapshot says: nothing changed.
 *
 * @template TKey of array-key
 * @template T
 *
 * @extends AbstractLazyCollection<TKey, T>
 */
final class LazyCollection extends AbstractLazyCollection
{
    /** @var array<TKey, T> */
    private array $snapshot = [];

    /** Whether it was changed since it was read or last flushed (Doctrine's PersistentCollection::isDirty()). */
    private bool $dirty = false;

    /** @var (\Closure(array<TKey, T>): \Doctrine\Common\Collections\Collection<TKey, T>)|null what holds the elements once read */
    private ?\Closure $wrap = null;

    /** @var list<T> the elements added before it was read */
    private array $added = [];

    /** Whether it was cleared since it was read or last flushed (an owning side's: its rows all go). */
    private bool $cleared = false;

    /**
     * @param \Closure(): array<TKey, T> $loader the elements, keyed as the association indexes them
     * @param bool                        $owning  the owning side of a many-to-many: clear() empties its snapshot, as
     *                                             PersistentCollection::clear() does for an owning side
     */
    public function __construct(private ?\Closure $loader, private readonly bool $owning = false)
    {
    }

    /**
     * A collection read already, holding those elements as its snapshot: what a flush puts in place of a collection
     * the application made (Doctrine's PersistentCollection in place of an ArrayCollection).
     *
     * @template K of array-key
     * @template V
     *
     * @param array<K, V> $elements
     *
     * @return self<K, V>
     */
    public static function of(array $elements, bool $owning = false): self
    {
        $collection = new self(static fn (): array => $elements, $owning);
        $collection->initialize();

        return $collection;
    }

    /**
     * The elements as read or last flushed (read now if the collection was not).
     *
     * @return array<TKey, T>
     */
    public function snapshot(): array
    {
        $this->initialize();

        return $this->snapshot;
    }

    /** What it holds now becomes its snapshot (a flush wrote it). */
    public function takeSnapshot(): void
    {
        if ($this->isInitialized()) {
            $this->snapshot = $this->collection->toArray();
            $this->dirty = false;
            $this->cleared = false;
        }
    }

    public function wasCleared(): bool
    {
        return $this->cleared;
    }

    public function isDirty(): bool
    {
        return $this->dirty;
    }

    /**
     * The elements held, once read, by the collection $wrap makes of them (omnibase's OrderedArrayCollection, which
     * sorts them by #[OrderColumn]'s positions on first use): at once for one read already, its entries kept.
     *
     * @param \Closure(array<TKey, T>): \Doctrine\Common\Collections\Collection<TKey, T> $wrap
     */
    public function wrap(\Closure $wrap): void
    {
        if ($this->isInitialized()) {
            $this->collection = $wrap($this->collection->toArray());

            return;
        }
        $this->wrap = $wrap;
    }

    /**
     * An element added, the collection not read for it (PersistentCollection::add()): kept until it is read, then
     * after the elements read (unless one of them already).
     */
    public function add(mixed $element): void
    {
        $this->dirty = true;
        if (!$this->isInitialized()) {
            $this->added[] = $element;

            return;
        }
        parent::add($element);
    }

    public function remove(string|int $key): mixed
    {
        $removed = parent::remove($key);
        // Changed only when something was taken out, as PersistentCollection says.
        $this->dirty = $this->dirty || null !== $removed;

        return $removed;
    }

    public function removeElement(mixed $element): bool
    {
        $removed = parent::removeElement($element);
        $this->dirty = $this->dirty || $removed;

        return $removed;
    }

    public function set(string|int $key, mixed $value): void
    {
        parent::set($key, $value);
        $this->dirty = true;
    }

    public function clear(): void
    {
        $empty = $this->isInitialized() && $this->isEmpty();
        parent::clear();
        if ($empty) {
            return;
        }
        $this->dirty = true;
        if ($this->owning) {
            // PersistentCollection::clear() on an owning side: its rows deleted at the flush, its snapshot taken now
            // (empty) - what the elements added next are compared with -, and no longer dirty until one is.
            $this->snapshot = [];
            $this->cleared = true;
            $this->dirty = false;
        }
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        null === $offset ? $this->add($value) : $this->set($offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->remove($offset);
    }

    protected function doInitialize(): void
    {
        $loader = $this->loader;
        $this->loader = null;
        $this->snapshot = null === $loader ? [] : $loader();
        $this->collection = null === $this->wrap ? new ArrayCollection($this->snapshot) : ($this->wrap)($this->snapshot);
        $this->wrap = null;
        // Doctrine's restoreNewObjectsInDirtyCollection(): what was added before the read comes after, once.
        foreach ($this->added as $element) {
            if (!\is_object($element) || !\in_array($element, $this->snapshot, true)) {
                $this->collection->add($element);
            }
        }
        $this->added = [];
    }
}
