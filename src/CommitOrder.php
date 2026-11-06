<?php

namespace Omnibridge\Eloquent;

/**
 * The order a flush writes entities in, as Doctrine's commit order computes it (its TopologicalSort): an entity
 * after those it depends upon - the new entities its owning to-one associations point to, for the inserts; for
 * the deletes, an entity before those it points to -, a depth-first walk in the order the nodes were added, so
 * that two backends given the same work write the same rows in the same order (and the database gives the same
 * identifiers).
 *
 * An optional edge (a nullable foreign key) in a cycle is the one broken, the closest one backtracking: that
 * entity is inserted with NULL there and updated once the other has its row (an extra update). A cycle of
 * mandatory edges cannot be written: refused (InvalidPersistence::cycle()).
 */
final class CommitOrder
{
    private const NOT_VISITED = 1;
    private const IN_PROGRESS = 2;
    private const VISITED = 3;

    /** @var array<int, object> */
    private array $nodes = [];

    /** @var array<int, int> */
    private array $states = [];

    /** @var array<int, array<int, bool>> from => to => optional */
    private array $edges = [];

    /** @var list<object> */
    private array $result = [];

    public function add(object $node): void
    {
        $id = spl_object_id($node);
        $this->nodes[$id] = $node;
        $this->states[$id] = self::NOT_VISITED;
        $this->edges[$id] = [];
    }

    public function has(object $node): bool
    {
        return isset($this->nodes[spl_object_id($node)]);
    }

    /** $from depends upon $to: $to comes first. A mandatory edge is never made optional again. */
    public function depends(object $from, object $to, bool $optional): void
    {
        $fromId = spl_object_id($from);
        $toId = spl_object_id($to);
        if (false === ($this->edges[$fromId][$toId] ?? null)) {
            return;
        }
        $this->edges[$fromId][$toId] = $optional;
    }

    /** @return list<object> */
    public function sort(): array
    {
        foreach (array_keys($this->nodes) as $id) {
            if (self::NOT_VISITED === $this->states[$id]) {
                $this->visit($id);
            }
        }

        return $this->result;
    }

    private function visit(int $id): void
    {
        if (self::IN_PROGRESS === $this->states[$id]) {
            throw new CycleFound($this->nodes[$id]);
        }
        if (self::VISITED === $this->states[$id]) {
            return;
        }
        $this->states[$id] = self::IN_PROGRESS;
        foreach ($this->edges[$id] as $adjacent => $optional) {
            try {
                $this->visit($adjacent);
            } catch (CycleFound $cycle) {
                if ($cycle->collected) {
                    throw $cycle;
                }
                if ($optional) {
                    continue;
                }
                $this->states[$id] = self::NOT_VISITED;
                $cycle->add($this->nodes[$id]);

                throw $cycle;
            }
        }
        $this->states[$id] = self::VISITED;
        $this->result[] = $this->nodes[$id];
    }
}
