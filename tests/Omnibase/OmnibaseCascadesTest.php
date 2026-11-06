<?php

namespace Omnibridge\Eloquent\Tests\Omnibase;

use Base\Database\Attribute\Cascade;
use Base\Database\Attribute\OrphanRemoval;
use Omnibridge\Eloquent\Cascades;
use Omnibridge\Eloquent\Omnibase\OmnibaseCascades;
use Omnibridge\Eloquent\Tests\Fixtures\Entity\Thread;
use Omnibridge\Schema\Builder;
use PHPUnit\Framework\TestCase;

/** A class whose associations omnibase's attributes rewrite, on the property and on the class. */
#[OrphanRemoval(column: 'tags')]
class CascadedThread extends Thread
{
    #[Cascade(value: ['persist'])]
    #[OrphanRemoval(value: false)]
    protected \Doctrine\Common\Collections\Collection $translations;
}

/**
 * omnibase's #[Cascade] and #[OrphanRemoval] read on Eloquent as Doctrine's loadClassMetadata() applies them (step F7c):
 * on the property, or on the class naming it; the class's own over its parents', over the mapping's.
 */
final class OmnibaseCascadesTest extends TestCase
{
    public function testOmnibasesAttributesRewriteTheMappingsCascades(): void
    {
        $description = (new Builder())->build([Thread::class]);
        $translations = $description->entities[Thread::class]->relations['translations'];
        $tags = $description->entities[Thread::class]->relations['tags'];

        $cascades = new Cascades();
        self::assertTrue($cascades->cascades(Thread::class, $translations, 'remove'), 'the mapping says persist and remove');
        self::assertTrue($cascades->orphanRemoval(Thread::class, $translations));
        self::assertFalse($cascades->orphanRemoval(Thread::class, $tags));

        $cascades->extend(OmnibaseCascades::resolver());
        self::assertTrue($cascades->cascades(CascadedThread::class, $translations, 'persist'));
        self::assertFalse($cascades->cascades(CascadedThread::class, $translations, 'remove'), '#[Cascade] replaces the list');
        self::assertFalse($cascades->orphanRemoval(CascadedThread::class, $translations));
        self::assertTrue($cascades->orphanRemoval(CascadedThread::class, $tags), 'on the class, naming the property');
        self::assertTrue($cascades->orphanRemoval(Thread::class, $translations), 'the parent keeps its mapping');
    }
}
