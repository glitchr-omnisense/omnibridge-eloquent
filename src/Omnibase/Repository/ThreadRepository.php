<?php

namespace Omnibridge\Eloquent\Omnibase\Repository;

use Base\Attributes\Traits\HierarchifyTrait;
use Base\Entity\Thread;
use Base\Enum\WorkflowState;
use Base\Repository\Threads;
use Omnibridge\Eloquent\Omnibase\EloquentEntities;

/**
 * omnibridge/doctrine's ThreadRepository on Eloquent (plan "omnibase agnostic", step F7b): the same finders, compiled by
 * EloquentFinder. getHierarchy() reads the class's #[Hierarchify] (its own, else its nearest parent's).
 *
 * @extends EloquentEntities<Thread>
 */
class ThreadRepository extends EloquentEntities implements Threads
{
    use HierarchifyTrait;

    public function oneBySlug(string $slug, bool $cached = true): ?Thread
    {
        return $cached ? $this->cacheOneBySlug($slug) : $this->findOneBySlug($slug);
    }

    public function oneBySlugAndState(?string $slug, array|string $states, array $orderBy = []): ?Thread
    {
        // The finder's WithRoute: the slug only when there is one (no criterion at all on an empty one).
        return $this->cacheOneBySlugWithRouteAndState(['slug' => $slug], 'slug', $states, [], $orderBy);
    }

    public function oneByState(array|string $states, array $orderBy = []): ?Thread
    {
        return $this->cacheOneByState($states, [], $orderBy);
    }

    public function first(array $orderBy): ?Thread
    {
        return $this->cacheOneBy([], $orderBy);
    }

    public function byState(array|string $states, array $orderBy = []): array
    {
        return $this->cacheByState($states, [], $orderBy)->getResult();
    }

    public function byStateUncached(array|string $states): array
    {
        return $this->findByState($states)->getResult();
    }

    public function byStates(array $states): array
    {
        return $this->findBy(['state' => $states]);
    }

    public function oneByParentAndSlugAndState(?Thread $parent, string $slug, array|string $states, array $orderBy = []): ?Thread
    {
        return $this->cacheOneByParentAndSlugAndState($parent, $slug, $states, [], $orderBy);
    }

    public function byParentAndState(?Thread $parent, array|string $states, array $orderBy = [], ?int $limit = null, ?int $offset = null): array
    {
        return $this->cacheByParentAndState($parent, $states, [], $orderBy, $limit, $offset)->getResult();
    }

    public function byParentAndStatePublishedWithin(?Thread $parent, array|string $states, string $within, array $orderBy = []): array
    {
        return $this->cacheByParentAndStateAndPublishedAtWithin($parent, $states, $within, [], $orderBy)->getResult();
    }

    public function oneByStateAndSlugStartingWith(array|string $states, string $prefix): ?Thread
    {
        return $this->cacheOneByStateAndSlugStartingWith($states, $prefix);
    }

    public function countByStatePublishedYoungerThan(array|string $states, int|string $age): int
    {
        return $this->countByStateAndPublishedAtYoungerThan($states, $age);
    }

    public function previousOne(array $at, array|string $states, ?string $class = null): ?Thread
    {
        return null === $class
            ? $this->cachePreviousOneAndState($at, $states)
            : $this->cachePreviousOneByClassOfAndState($at, $class, $states);
    }

    public function nextOne(array $at, array|string $states, ?string $class = null): ?Thread
    {
        return null === $class
            ? $this->cacheNextOneAndState($at, $states)
            : $this->cacheNextOneByClassOfAndState($at, $class, $states);
    }

    /** The approved children of a thread counted, as omnibridge/doctrine's ThreadRepository::countForChildrenIn() reads its grouped count. */
    public function countForChildrenIn($thread)
    {
        $nDiscussions = $this->countByParentAndWorkflow($thread, WorkflowState::APPROVED, [], '', null, ['children']);
        $nComments = [];
        foreach ($nDiscussions as $entry) {
            $nComments[$entry[$thread->getId()]] = ($nComments[$entry[$thread->getId()]] ?? 0) + $entry['count'];
        }

        return $nComments;
    }

    public function cacheByInsensitiveIdentifier($identifier, array $fields = [])
    {
        return $this->findByInsensitiveIdentifier($identifier, $fields, true);
    }

    public function findByInsensitiveIdentifier($identifier, array $fields = [], $cacheable = false)
    {
        if (empty($fields)) {
            $fields[] = 'slug';
        }

        // LOWER(<field>) LIKE %identifier% on any of the fields: a finder of the language, one criterion per field.
        $identifier = '%'.strtolower(preg_replace('/%$/', '', $identifier)).'%';
        $finder = ($cacheable ? 'cacheBy' : 'findBy').implode('Or', array_map(static fn (string $field) => 'InsensitivePartial'.ucfirst($field), $fields));

        return $this->{$finder}(...array_fill(0, \count($fields), $identifier));
    }

    /**
     * The class's #[Hierarchify] as HierarchifyTrait reads it from Doctrine's metadata completor: its own, else its
     * nearest parent's.
     */
    public function getClassMetadataCompletor(): object
    {
        for ($class = $this->getClassName(); false !== $class; $class = get_parent_class($class)) {
            foreach ((new \ReflectionClass($class))->getAttributes(\Base\Database\Attribute\Hierarchify::class) as $attribute) {
                $hierarchify = $attribute->newInstance();

                return (object) ['entityHierarchy' => $hierarchify->hierarchy, 'entityHierarchySeparator' => $hierarchify->separator];
            }
        }

        return (object) ['entityHierarchy' => null, 'entityHierarchySeparator' => null];
    }
}
