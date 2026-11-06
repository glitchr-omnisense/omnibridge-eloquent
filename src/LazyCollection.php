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

    /** @param \Closure(): array<TKey, T> $loader the elements, keyed as the association indexes them */
    public function __construct(private ?\Closure $loader)
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
    public static function of(array $elements): self
    {
        $collection = new self(static fn (): array => $elements);
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
        }
    }

    protected function doInitialize(): void
    {
        $loader = $this->loader;
        $this->loader = null;
        $this->snapshot = null === $loader ? [] : $loader();
        $this->collection = new ArrayCollection($this->snapshot);
    }
}
