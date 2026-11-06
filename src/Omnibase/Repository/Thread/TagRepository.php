<?php

namespace Omnibridge\Eloquent\Omnibase\Repository\Thread;

use Base\Entity\Thread\Tag;
use Base\Repository\Thread\Tags;
use Omnibridge\Eloquent\Omnibase\EloquentEntities;

/**
 * omnibridge/doctrine's Thread\TagRepository on Eloquent (plan "omnibase agnostic", step F7b): the same finders, compiled by
 * EloquentFinder.
 *
 * @extends EloquentEntities<Tag>
 */
class TagRepository extends EloquentEntities implements Tags
{
    public function oneBySlug(string $slug): ?Tag
    {
        return $this->cacheOneBySlug($slug);
    }

    public function bySlugs(array $slugs): array
    {
        return $this->cacheBySlug($slugs)->getResult();
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
}
