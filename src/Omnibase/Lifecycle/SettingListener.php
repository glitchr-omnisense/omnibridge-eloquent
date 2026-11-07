<?php

namespace Omnibridge\Eloquent\Omnibase\Lifecycle;

use Base\Database\Lifecycle\SettingsInvalidation;
use Omnibridge\Persistence\Lifecycle;

/**
 * The settings' snapshot kept in step on omnibridge/eloquent (plan "omnibase agnostic", step F7c): omnibridge/doctrine's
 * SettingSubscriber, the rule the core's (SettingsInvalidation): a Setting or SettingIntl written empties it.
 */
final class SettingListener
{
    private ?SettingsInvalidation $invalidation = null;

    /** @param \Closure(): \Base\Service\SettingBagInterface $settingBag */
    public function __construct(private readonly \Closure $settingBag)
    {
    }

    public function __invoke(object $event): void
    {
        $invalidation = $this->invalidation ??= new SettingsInvalidation($this->settingBag);
        match (true) {
            $event instanceof Lifecycle\PostPersist => $invalidation->onPostPersist($event),
            $event instanceof Lifecycle\PostUpdate => $invalidation->onPostUpdate($event),
            $event instanceof Lifecycle\PostRemove => $invalidation->onPostRemove($event),
            default => null,
        };
    }
}
