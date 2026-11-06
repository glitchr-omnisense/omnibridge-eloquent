<?php

namespace Omnibridge\Eloquent\Omnibase\Repository;

use Base\Database\Repository\Finder\Group;
use Base\Entity\Thread;
use Base\Entity\ThreadIntl;
use Base\Repository\ThreadTranslations;
use Omnibridge\Eloquent\Omnibase\EloquentEntities;

/**
 * omnibridge/doctrine's ThreadIntlRepository on Eloquent (plan "omnibase agnostic", step F7b): the same finders, compiled by
 * EloquentFinder.
 *
 * @extends EloquentEntities<ThreadIntl>
 */
class ThreadIntlRepository extends EloquentEntities implements ThreadTranslations
{
    public function search(array $terms, array $states = [], Thread|int|string|null $parent = null): array
    {
        $terms = array_filter(
            array_intersect_key($terms, ['title' => true, 'excerpt' => true, 'content' => true]),
            fn ($term) => \is_string($term) && '' !== trim($term)
        );
        if (!$terms) {
            return [];
        }

        // (LOWER(title) LIKE %term% OR LOWER(excerpt) LIKE ... ), then the thread's states and parent.
        $names = $values = [];
        foreach ($terms as $field => $term) {
            $names[] = 'InsensitivePartial'.ucfirst($field);
            $values[] = '%'.mb_strtolower(trim($term)).'%';
        }
        $criteria = [Group::by(implode('Or', $names), ...$values)];
        if ($states) {
            $criteria['translatable.state'] = $states;
        }
        if ($parent) {
            $criteria['translatable.parent'] = $parent;
        }

        // The translated thread joined, always (TranslatableNotNull): its trash scope keeps the texts of a deleted thread out.
        return $this->findByTranslatableNotNull($criteria)->getResult();
    }
}
