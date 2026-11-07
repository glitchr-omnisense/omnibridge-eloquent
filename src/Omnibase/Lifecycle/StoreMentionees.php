<?php

namespace Omnibridge\Eloquent\Omnibase\Lifecycle;

use Base\Repository\Thread\Mentions;
use Base\Service\Model\Wysiwyg\MentionEnhancerInterface;
use Omnibridge\Eloquent\EntityStore;

/**
 * The host's mention enhancer, its mentionees given as the store holds them (plan "omnibase agnostic", step F7c): the
 * enhancer finds the users a text mentions through the host's repositories, which may be another persistence's (a
 * site still on Doctrine beside the store, F7c's proofs); a Mention written by the unit of work must point to the
 * store's user, so each one is read again from the store by its identifier. Everything else is the enhancer's.
 */
final class StoreMentionees implements MentionEnhancerInterface
{
    public function __construct(private readonly MentionEnhancerInterface $enhancer, private readonly EntityStore $store)
    {
    }

    public function mentions(): Mentions
    {
        return $this->enhancer->mentions();
    }

    public function getRepository(): Mentions
    {
        return $this->enhancer->getRepository();
    }

    public function extractMentionees(string|array|null $strOrArray, array $attributes = []): array
    {
        $mentionees = [];
        foreach ($this->enhancer->extractMentionees($strOrArray, $attributes) as $key => $mentionee) {
            $mentionees[$key] = \is_object($mentionee) ? $this->held($mentionee) : $mentionee;
        }

        return $mentionees;
    }

    public function enhance(string|array|null $strOrArray, array $attributes = []): string|array|null
    {
        return $this->enhancer->enhance($strOrArray, $attributes);
    }

    private function held(object $entity): object
    {
        $class = $entity::class;
        if (false !== ($marker = strrpos($class, '\\__CG__\\'))) {
            $class = substr($class, $marker + 8);
        }
        if (!$this->store->mapping()->has($class) || !method_exists($entity, 'getId') || null === $entity->getId()) {
            return $entity;
        }

        return $this->store->find($class, $entity->getId()) ?? $entity;
    }
}
