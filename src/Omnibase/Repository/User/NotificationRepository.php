<?php

namespace Omnibridge\Eloquent\Omnibase\Repository\User;

use Base\Database\Repository\Finder\Group;
use Base\Entity\User;
use Base\Entity\User\Notification;
use Base\Repository\User\Notifications;
use Omnibridge\Eloquent\Omnibase\EloquentEntities;

/**
 * omnibridge/doctrine's User\NotificationRepository on Eloquent (plan "omnibase agnostic", step F7b): the same finders, compiled by
 * EloquentFinder.
 *
 * @extends EloquentEntities<Notification>
 */
class NotificationRepository extends EloquentEntities implements Notifications
{
    public function countUnreadFor(User $user): int
    {
        return $this->count($this->visibleTo($user) + ['isRead' => false]);
    }

    public function findVisibleFor(User $user, int $limit = 100, int $offset = 0, bool $unreadOnly = false): array
    {
        return $this->findBy($this->visibleTo($user) + ($unreadOnly ? ['isRead' => false] : []), ['sentAt' => 'DESC', 'id' => 'DESC'], $limit, $offset);
    }

    /** @return array<int|string, mixed> the user's, with a title, a subject or a content */
    private function visibleTo(User $user): array
    {
        return ['user' => $user, Group::by('TitleNotEmptyOrSubjectNotEmptyOrContentNotEmpty')];
    }
}
