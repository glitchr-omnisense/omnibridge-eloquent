<?php

namespace Omnibridge\Eloquent\Omnibase\Repository\Layout;

use Base\Entity\Layout\Setting;
use Base\Repository\Layout\Settings;
use Omnibridge\Eloquent\Omnibase\EloquentEntities;

/**
 * omnibridge/doctrine's Layout\SettingRepository on Eloquent (plan "omnibase agnostic", step F7b): the same finders, compiled by
 * EloquentFinder.
 *
 * @extends EloquentEntities<Setting>
 */
class SettingRepository extends EloquentEntities implements Settings
{
    public function under(?string $path, bool $linkedBagOnly = false, bool $cached = true): array
    {
        $fn = $cached ? 'cache' : 'find';
        $fn .= $linkedBagOnly
            ? ($path ? 'ByInsensitivePathStartingWithAndBagNotEmpty' : 'ByBagNotEmpty')
            : ($path ? 'ByInsensitivePathStartingWith' : 'All');
        $settings = $this->$fn(...($path ? [$path] : []));

        return \is_array($settings) ? $settings : $settings->getResult();
    }

    public function oneByInsensitivePath(string $path): ?Setting
    {
        return $this->findOneByInsensitivePath($path);
    }
}
