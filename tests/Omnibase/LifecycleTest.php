<?php

namespace Omnibridge\Eloquent\Tests\Omnibase;

use Base\Attributes\AbstractAttribute;
use Base\Database\Entity\EntityExtension;
use Base\Database\Entity\EntityExtensionInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Omnibridge\Eloquent\LazyCollection;
use Omnibridge\Eloquent\Omnibase\AttributeRelay;
use Omnibridge\Eloquent\Omnibase\Lifecycle\OmnibaseListeners;
use Omnibridge\Eloquent\UnsupportedPersistence;
use PHPUnit\Framework\TestCase;

/**
 * omnibase's lifecycle on omnibridge/eloquent (plan "omnibase agnostic", step F7c, second part), what needs no host:
 * what is refused aloud, and the collections' changes as Doctrine's PersistentCollection tells them. The behaviours
 * themselves run in omnisense/omnibase's harness (tests/Harness/WriteParityTest, the core's behaviour tests).
 */
final class LifecycleTest extends TestCase
{
    public function testAnAttributeOverridingADoctrineTypedHookIsRefused(): void
    {
        $relay = (new \ReflectionClass(AttributeRelay::class))->newInstanceWithoutConstructor();
        $refuse = new \ReflectionMethod(AttributeRelay::class, 'refuseDoctrineHook');
        $doctrineTyped = new class extends AbstractAttribute {
            public function supports(string $target, ?string $targetValue = null, $object = null): bool
            {
                return true;
            }

            public function onFlush(OnFlushEventArgs $event, ClassMetadata $classMetadata, mixed $entity, ?string $property = null)
            {
            }
        };

        $refuse->invoke($relay, $doctrineTyped, 'preFlush'); // its own hooks of the other moments are AbstractAttribute's

        $this->expectException(UnsupportedPersistence::class);
        $this->expectExceptionMessage('::onFlush(), a Doctrine-typed hook');
        $refuse->invoke($relay, $doctrineTyped, 'onFlush');
    }

    public function testAnEntityExtensionIsRefused(): void
    {
        $extension = new EntityExtension();
        $extension->addExtension($this->createStub(EntityExtensionInterface::class));
        $unitOfWork = (new \ReflectionClass(\Omnibridge\Eloquent\EloquentUnitOfWork::class))->newInstanceWithoutConstructor();

        $this->expectException(UnsupportedPersistence::class);
        $this->expectExceptionMessage('write the entries of an EntityExtension');
        OmnibaseListeners::attach($unitOfWork, ['entity_extension' => $extension]);
    }

    public function testACollectionIsDirtyAsAPersistentCollectionIs(): void
    {
        $a = new \stdClass();
        $b = new \stdClass();
        $c = new \stdClass();
        $collection = new LazyCollection(static fn (): array => [$a, $b]);
        self::assertFalse($collection->isDirty());

        $collection->add($c);
        self::assertFalse($collection->isInitialized(), 'added without being read');
        self::assertTrue($collection->isDirty());
        self::assertSame([$a, $b, $c], $collection->toArray(), 'what was added comes after what was read');
        self::assertSame([$a, $b], $collection->snapshot());

        $collection->takeSnapshot();
        self::assertFalse($collection->isDirty());
        self::assertFalse($collection->removeElement(new \stdClass()));
        self::assertFalse($collection->isDirty(), 'nothing taken out: not changed');
        self::assertNull($collection->remove(42));
        self::assertFalse($collection->isDirty());

        $collection->clear();
        self::assertTrue($collection->isDirty(), 'an inverse side cleared is changed');
        self::assertFalse($collection->wasCleared());
    }

    public function testAnOwningSideClearedTakesItsSnapshotAsDoctrinesDoes(): void
    {
        $a = new \stdClass();
        $collection = new LazyCollection(static fn (): array => [$a], true);
        $collection->clear();
        self::assertTrue($collection->wasCleared());
        self::assertSame([], $collection->snapshot());
        self::assertFalse($collection->isDirty(), 'cleared only: its rows go, it is not dirty');
        $collection->add($a);
        self::assertTrue($collection->isDirty());
        $collection->takeSnapshot();
        self::assertFalse($collection->wasCleared());
    }

    public function testACollectionWrappedOnceRead(): void
    {
        $a = new \stdClass();
        $b = new \stdClass();
        $collection = new LazyCollection(static fn (): array => [$a, $b]);
        $collection->wrap(static fn (array $elements) => new \Doctrine\Common\Collections\ArrayCollection(array_reverse($elements)));
        self::assertFalse($collection->isInitialized());
        self::assertSame([$b, $a], $collection->toArray());
        self::assertSame([$a, $b], $collection->snapshot(), 'the snapshot is what was read');
    }
}
