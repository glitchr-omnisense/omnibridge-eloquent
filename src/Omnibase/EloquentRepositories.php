<?php

namespace Omnibridge\Eloquent\Omnibase;

use Base\Database\Repository\Entities;
use Base\Database\Repository\Repositories;
use Omnibridge\Eloquent\EloquentUnitOfWork;
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

    /** @var array<class-string, \Closure(EntityStore, class-string, ?EloquentUnitOfWork): EloquentEntities<object>> by repository class */
    private array $factories = [];

    /** @param array<class-string, class-string<EloquentEntities<object>>> $classes an application's repositories by entity, over the core's */
    public function __construct(private readonly EntityStore $store, array $classes = [], private readonly ?EloquentUnitOfWork $unitOfWork = null)
    {
        $this->classes = $classes + self::CORE;
    }

    /**
     * An application's repository for an entity and its subclasses (its own class extending EloquentEntities, or one of
     * the core's repositories), in place of the core's or the plain one. $factory makes it when it needs more than
     * the store, the class and the unit of work (a cache: lesringostars' comments' counts), given those three.
     *
     * @param class-string                                                                                 $entity
     * @param class-string<EloquentEntities<object>>                                                      $repository
     * @param (\Closure(EntityStore, class-string, ?EloquentUnitOfWork): EloquentEntities<object>)|null $factory
     */
    public function register(string $entity, string $repository, ?\Closure $factory = null): void
    {
        if (!is_a($repository, EloquentEntities::class, true)) {
            throw new \InvalidArgumentException(sprintf('%s is no repository on the store (EloquentEntities).', $repository));
        }
        $this->classes = [$entity => $repository] + $this->classes;
        if (null !== $factory) {
            $this->factories[$repository] = $factory;
        }
        $this->repositories = [];
    }

    public function of(string $class): Entities
    {
        if (isset($this->repositories[$class])) {
            return $this->repositories[$class];
        }
        $repository = $this->repositoryClass($class);
        $made = isset($this->factories[$repository]) ? ($this->factories[$repository])($this->store, $class, $this->unitOfWork) : new $repository($this->store, $class, $this->unitOfWork);
        if (!$made instanceof $repository) {
            throw new \LogicException(sprintf('The factory of %s made a %s.', $repository, get_debug_type($made)));
        }

        return $this->repositories[$class] = $made;
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
        // The repository that declares the interface before one that has it from its parent: an application's
        // ArticleRepository (Articles, a Threads) registered ahead of the core's does not answer Threads. Classes are
        // loaded in order until that one, as before.
        $inherited = null;
        foreach ($this->classes as $entity => $repository) {
            if (!is_a($repository, $interface, true)) {
                continue;
            }
            $parent = get_parent_class($repository);
            if (false !== $parent && is_a($parent, $interface, true)) {
                $inherited ??= $entity;
                continue;
            }

            return $this->implementedBy($interface, $class ?? $entity);
        }
        if (null !== $inherited) {
            return $this->implementedBy($interface, $class ?? $inherited);
        }

        throw new \OutOfBoundsException(sprintf('No repository on Eloquent implements %s.', $interface));
    }

    /**
     * @template T of Entities
     *
     * @param class-string<T> $interface
     *
     * @return T
     */
    private function implementedBy(string $interface, string $class): Entities
    {
        $repository = $this->of($class);
        if (!$repository instanceof $interface) {
            throw new \LogicException(sprintf('The repository of %s (%s) does not implement %s.', $class, $repository::class, $interface));
        }

        return $repository;
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
