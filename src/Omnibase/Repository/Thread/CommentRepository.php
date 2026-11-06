<?php

namespace Omnibridge\Eloquent\Omnibase\Repository\Thread;

use Base\Database\Repository\Finder\Key;
use Base\Entity\Thread;
use Base\Entity\Thread\Comment;
use Base\Enum\CommentState;
use Base\Repository\Thread\Comments;
use Omnibridge\Eloquent\Omnibase\EloquentEntities;

/**
 * omnibridge/doctrine's Thread\CommentRepository on Eloquent (plan "omnibase agnostic", step F7b): the same finders, compiled by
 * EloquentFinder.
 *
 * @extends EloquentEntities<Comment>
 */
class CommentRepository extends EloquentEntities implements Comments
{
    public function findVisible(?Thread $thread, int $limit = 200): array
    {
        return $this->findBy($this->visible($thread) + ['parent' => Key::of(null)], ['createdAt' => 'DESC'], $limit);
    }

    public function countVisible(?Thread $thread): int
    {
        return $this->count($this->visible($thread));
    }

    /** @return array<string, mixed> approved, under the thread compared by its key (null: the visitors' book) */
    private function visible(?Thread $thread): array
    {
        return ['state' => CommentState::APPROVED, 'thread' => Key::of($thread)];
    }

    public function countPending(): int
    {
        return $this->count(['state' => CommentState::PENDING]);
    }

    public function findPending(int $limit = 20): array
    {
        return $this->findBy(['state' => CommentState::PENDING], ['createdAt' => 'ASC'], $limit);
    }

    public function findLastFromIp(string $ip): ?Comment
    {
        return $this->findOneBy(['ip' => $ip], ['createdAt' => 'DESC']);
    }
}
