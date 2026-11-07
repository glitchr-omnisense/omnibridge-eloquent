<?php

namespace Omnibridge\Eloquent\Omnibase\Lifecycle;

use Base\Demo\DemoAccountLock;
use Base\Entity\User;
use Omnibridge\Contract\Requests;
use Omnibridge\Eloquent\EloquentUnitOfWork;
use Omnibridge\Persistence\Lifecycle;

/**
 * A demonstration account kept as declared, on omnibridge/eloquent (plan "omnibase agnostic", step F7c):
 * omnibridge/doctrine's DemoAccountLockSubscriber, the rule the core's (DemoAccountLock). During a request, at
 * PreFlush: a declared account's deletion refused, and each declared account the unit of work holds given back its
 * identifier, address, password and second factor (the values read and written as the store holds them), the
 * visitor told once.
 */
final class DemoAccountLockListener
{
    private bool $told = false;

    public function __construct(private readonly DemoAccountLock $lock, private readonly ?Requests $requests = null)
    {
    }

    public function __invoke(object $event): void
    {
        if (!$event instanceof Lifecycle\PreFlush || !$event->unitOfWork instanceof EloquentUnitOfWork) {
            return;
        }
        if (null === $this->requests || !$this->requests->has()) {
            return;
        }
        $unitOfWork = $event->unitOfWork;
        $store = $unitOfWork->store();

        foreach ($unitOfWork->scheduledDeletions() as $entity) {
            if ($entity instanceof User && $this->lock->declarationOf($entity, $store->original($entity))) {
                throw self::refusal($this->lock->message('deleted'));
            }
        }

        $refused = false;
        foreach ($store->identityMap()->all() as $entity) {
            if (!$entity instanceof User || $store->isUninitialized($entity)) {
                continue;
            }
            $original = $store->original($entity);
            if ($account = $this->lock->declarationOf($entity, $original)) {
                $fields = array_keys($store->mapping()->of($entity::class)->entity->fields);
                $refused = $this->lock->restore(
                    $entity,
                    $account,
                    $original,
                    $fields,
                    static fn (string $field): mixed => $store->value($entity, $field),
                    static function (string $field, mixed $value) use ($store, $entity): void {
                        $store->setValue($entity, $field, $value);
                    },
                ) || $refused;
            }
        }

        if ($refused && !$this->told) {
            $this->requests->flash('warning', $this->lock->message('locked'));
            $this->told = true;
        }
    }

    public function reset(): void
    {
        $this->told = false;
    }

    private static function refusal(string $message): \Throwable
    {
        $denied = 'Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException';

        return class_exists($denied) ? new $denied($message) : new \RuntimeException($message);
    }
}
