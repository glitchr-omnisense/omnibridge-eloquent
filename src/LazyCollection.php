<?php

namespace Omnibridge\Eloquent;

use Doctrine\Common\Collections\AbstractLazyCollection;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * A to-many association read when it is first used: doctrine/collections' Collection, which the entities
 * type their associations on (ArrayCollection when they make them, Doctrine's PersistentCollection when
 * Doctrine reads them), loaded by the store on the first call that needs its elements.
 *
 * @template TKey of array-key
 * @template T
 *
 * @extends AbstractLazyCollection<TKey, T>
 */
final class LazyCollection extends AbstractLazyCollection
{
    /** @param \Closure(): array<TKey, T> $loader the elements, keyed as the association indexes them */
    public function __construct(private ?\Closure $loader)
    {
    }

    protected function doInitialize(): void
    {
        $loader = $this->loader;
        $this->loader = null;
        $this->collection = new ArrayCollection(null === $loader ? [] : $loader());
    }
}
