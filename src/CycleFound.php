<?php

namespace Omnibridge\Eloquent;

/** @internal a cycle of mandatory edges met while sorting (Doctrine's CycleDetectedException) */
final class CycleFound extends \RuntimeException
{
    /** @var list<object> */
    public array $cycle;

    public bool $collected = false;

    public function __construct(private readonly object $start)
    {
        parent::__construct('A cycle of mandatory foreign keys.');
        $this->cycle = [$start];
    }

    public function add(object $node): void
    {
        array_unshift($this->cycle, $node);
        if ($node === $this->start) {
            $this->collected = true;
        }
    }
}
