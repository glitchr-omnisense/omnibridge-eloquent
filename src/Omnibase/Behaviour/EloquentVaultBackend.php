<?php

namespace Omnibridge\Eloquent\Omnibase\Behaviour;

use Base\Database\Attribute\Backend\VaultBackend;
use Omnibridge\Eloquent\EloquentUnitOfWork;

/**
 * #[Vault]'s part on omnibridge/eloquent (plan "omnibase agnostic", step F7c): a secured field given a new plain value
 * is written again, the entity scheduled for an update whatever its change set says - Doctrine's scheduleForUpdate().
 */
final class EloquentVaultBackend implements VaultBackend
{
    public function __construct(private readonly EloquentUnitOfWork $unitOfWork)
    {
    }

    public function scheduleUpdate(object $entity): void
    {
        $this->unitOfWork->scheduleForUpdate($entity);
    }
}
