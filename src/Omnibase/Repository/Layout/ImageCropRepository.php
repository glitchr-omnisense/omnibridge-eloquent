<?php

namespace Omnibridge\Eloquent\Omnibase\Repository\Layout;

use Base\Entity\Layout\ImageCrop;
use Base\Repository\Layout\ImageCrops;
use Omnibridge\Eloquent\Omnibase\EloquentEntities;

/**
 * omnibridge/doctrine's Layout\ImageCropRepository on Eloquent (plan "omnibase agnostic", step F7b): the same finders, compiled by
 * EloquentFinder.
 *
 * @extends EloquentEntities<ImageCrop>
 */
class ImageCropRepository extends EloquentEntities implements ImageCrops
{
    public function oneBySlugOfImage(string $slug, string $source): ?ImageCrop
    {
        return $this->cacheOneBySlug($slug, ['image.source' => $source]);
    }

    public function oneClosestToRatio(float|int $ratio0, string $source): ?ImageCrop
    {
        // The criteria, the order, the offset, the grouping, then the selected ratio.
        return $this->cacheOneByRatio0ClosestTo($ratio0, ['image.source' => $source], [], null, [], ['ratio0' => 'e.width0/e.height0'])[0] ?? null;
    }

    public function oneClosestToRatioAndSize(float|int $ratio0, float|int $width0, float|int $height0, string $source): ?ImageCrop
    {
        return $this->findOneByRatio0ClosestToAndWidth0ClosestToAndHeight0ClosestTo($ratio0, $width0, $height0, ['image.source' => $source], [], null, [], ['ratio0' => 'e.width0/e.height0'])[0] ?? null;
    }
}
