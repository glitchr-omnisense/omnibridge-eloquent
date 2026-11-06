<?php

namespace Omnibridge\Eloquent\Omnibase;

use Base\Database\Repository\Entities;
use Base\Database\Repository\Repositories;
use Omnibridge\Eloquent\EntityStore;
use Omnibridge\Eloquent\Omnibase\Repository as R;

/**
 * omnibase's Repositories on the store (plan "omnibase agnostic", step F7b): the repository of an entity - the
 * core's on Eloquent (Repository\*, each implementing the core's interface: Threads, Users, Comments...) for its
 * entity and the entity's subclasses (App\Entity\Article\Article gets ThreadRepository's), an application's own
 * where one is registered (register()), EloquentEntities for any other class (find, findBy, count and the finder
 * language) -, and the entities of identifiers: one query for those not read yet, the identity map for the others.
 */
final class EloquentRepositories implements Repositories
{
    /**
     * The core's entities and their repository on Eloquent, as omnibridge/doctrine's repositories serve the core's
     * interfaces (Base\Repository\Aliases::INTERFACES).
     *
     * @var array<class-string, class-string<EloquentEntities<object>>>
     */
    public const CORE = [
        \Base\Entity\User::class => R\UserRepository::class,
        \Base\Entity\Thread::class => R\ThreadRepository::class,
        \Base\Entity\ThreadIntl::class => R\ThreadIntlRepository::class,
        \Base\Entity\Thread\Tag::class => R\Thread\TagRepository::class,
        \Base\Entity\Thread\Comment::class => R\Thread\CommentRepository::class,
        \Base\Entity\Thread\Like::class => R\Thread\LikeRepository::class,
        \Base\Entity\Thread\Mention::class => R\Thread\MentionRepository::class,
        \Base\Entity\User\Notification::class => R\User\NotificationRepository::class,
        \Base\Entity\User\Connection::class => R\User\ConnectionRepository::class,
        \Base\Entity\User\Passkey::class => R\User\PasskeyRepository::class,
        \Base\Entity\User\Identity::class => R\User\IdentityRepository::class,
        \Base\Entity\User\Token::class => R\User\TokenRepository::class,
        \Base\Entity\User\PushSubscription::class => R\User\PushSubscriptionRepository::class,
        \Base\Entity\User\Complaint::class => R\User\ComplaintRepository::class,
        \Base\Entity\Layout\Setting::class => R\Layout\SettingRepository::class,
        \Base\Entity\Layout\Widget::class => R\Layout\WidgetRepository::class,
        \Base\Entity\Layout\Widget\Slot::class => R\Layout\Widget\SlotRepository::class,
        \Base\Entity\Layout\ImageCrop::class => R\Layout\ImageCropRepository::class,
        \Base\Entity\Layout\Redirection::class => R\Layout\RedirectionRepository::class,
        \Base\Entity\Layout\Semantic::class => R\Layout\SemanticRepository::class,
        // No interface of the core's, but a method of omnibridge/doctrine's repository the core calls (OverridingTranslator).
        \Base\Entity\Layout\TextOverride::class => R\Layout\TextOverrideRepository::class,
        \Base\Entity\Extension\TrashBall::class => R\Extension\TrashBallRepository::class,
        \Base\Entity\Extension\Revision::class => R\Extension\RevisionRepository::class,
        \Base\Entity\Analytics\PageView::class => R\Analytics\PageViewRepository::class,
        \Base\Entity\Analytics\PageVisit::class => R\Analytics\PageVisitRepository::class,
        \Base\Entity\Analytics\Visit::class => R\Analytics\VisitRepository::class,
        \Base\Entity\Hours\ScopedWeek::class => R\Hours\ScopedWeekRepository::class,
        \Base\Entity\Hours\SpecialDay::class => R\Hours\SpecialDayRepository::class,
        \Base\Entity\Hours\WeekDayHours::class => R\Hours\WeekDayHoursRepository::class,
    ];

    /** @var array<string, Entities<object>> */
    private array $repositories = [];

    /** @var array<class-string, class-string<EloquentEntities<object>>> */
    private array $classes;

    /** @param array<class-string, class-string<EloquentEntities<object>>> $classes an application's repositories by entity, over the core's */
    public function __construct(private readonly EntityStore $store, array $classes = [])
    {
        $this->classes = $classes + self::CORE;
    }

    /**
     * An application's repository for an entity and its subclasses (its own class extending EloquentEntities, or one of
     * the core's repositories), in place of the core's or the plain one.
     *
     * @param class-string                            $entity
     * @param class-string<EloquentEntities<object>> $repository
     */
    public function register(string $entity, string $repository): void
    {
        if (!is_a($repository, EloquentEntities::class, true)) {
            throw new \InvalidArgumentException(sprintf('%s is no repository on the store (EloquentEntities).', $repository));
        }
        $this->classes = [$entity => $repository] + $this->classes;
        $this->repositories = [];
    }

    public function of(string $class): Entities
    {
        return $this->repositories[$class] ??= new ($this->repositoryClass($class))($this->store, $class);
    }

    /**
     * The repository implementing one of the core's interfaces (Threads, Users, Comments...): the entity's whose
     * repository class implements it. A class of the application named when its entity is the application's (Users:
     * App\Entity\User).
     *
     * @template T of Entities
     *
     * @param class-string<T>   $interface
     * @param class-string|null $class     the entity to read, the interface's own by default
     *
     * @return T
     */
    public function implementing(string $interface, ?string $class = null): Entities
    {
        foreach ($this->classes as $entity => $repository) {
            if (is_a($repository, $interface, true)) {
                $repository = $this->of($class ?? $entity);
                if (!$repository instanceof $interface) {
                    throw new \LogicException(sprintf('The repository of %s (%s) does not implement %s.', $class ?? $entity, $repository::class, $interface));
                }

                return $repository;
            }
        }

        throw new \OutOfBoundsException(sprintf('No repository on Eloquent implements %s.', $interface));
    }

    /** @return class-string<EloquentEntities<object>> the repository of a class: its own, else its nearest parent's, else the plain one */
    public function repositoryClass(string $class): string
    {
        for ($candidate = (new \ReflectionClass($class))->getName(); false !== $candidate; $candidate = get_parent_class($candidate)) {
            if (isset($this->classes[$candidate])) {
                return $this->classes[$candidate];
            }
        }
        foreach ($this->classes as $entity => $repository) {
            if ($class === $entity) {
                return $repository;
            }
        }

        return EloquentEntities::class;
    }

    public function byIds(string $class, array $ids): array
    {
        return $this->store->findMany($class, array_values($ids));
    }

    public function oneById(string $class, mixed $id): ?object
    {
        return null === $id ? null : $this->store->find($class, $id);
    }
}
