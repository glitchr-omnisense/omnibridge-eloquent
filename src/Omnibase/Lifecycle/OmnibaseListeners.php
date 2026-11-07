<?php

namespace Omnibridge\Eloquent\Omnibase\Lifecycle;

use Base\Attributes\AttributeReader;
use Base\Database\Entity\EntityExtension;
use Base\Database\Entity\EntityHydrator;
use Base\Demo\DemoAccountLock;
use Base\EntityDispatcher\AbstractEventDispatcher;
use Omnibridge\Contract\Requests;
use Omnibridge\Eloquent\EloquentUnitOfWork;
use Omnibridge\Eloquent\Omnibase\AttributeRelay;
use Omnibridge\Eloquent\UnsupportedPersistence;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * omnibase's lifecycle on omnibridge/eloquent's unit of work, in one call (plan "omnibase agnostic", step F7c): the
 * behaviours' relay and the adapters of the listeners omnibridge/doctrine and omnibase register on Doctrine's events,
 * listening in Doctrine's order (by priority, then as they are declared: AttributeSubscriber and IntlSubscriber 2048,
 * TrasheableSubscriber 1024, VersionableSubscriber 512, then ThreadSubscriber, SettingSubscriber,
 * TextOverrideCacheListener, the entity dispatchers, UserSubscriber's and DemoAccountLockSubscriber's preFlush).
 *
 * Each adapter is attached when what it needs is given (a host wires them from its container, step F7d):
 *
 *     OmnibaseListeners::attach($persistence->unitOfWork(), [
 *         'empty_trash' => '+7 days', 'max_revisions' => 5,                  // TrasheableListener, VersionableListener
 *         'mention_enhancer' => fn () => $mentionEnhancer,                    // ThreadListener
 *         'setting_bag' => fn () => $settingBag,                              // SettingListener
 *         'translator' => fn () => $overridingTranslator,                     // TextOverrideListener
 *         'dispatcher' => $psr14, 'requests' => $requests,                    // the entity dispatchers' events
 *         'entity_dispatchers' => [UserEventDispatcher::class, ...],          // made on this unit of work (or the
 *                                                                             // host's, an iterable or a closure: read lazily)
 *         'user_subscriber' => fn () => $userSubscriber,                      // its preFlush()
 *         'demo_lock' => $demoAccountLock,                                    // DemoAccountLockListener
 *         'entity_extension' => $entityExtension,                             // refused aloud when it holds an extension
 *     ]);
 *
 * Not adapted, with the reason: InverseCollectionCacheSubscriber (it evicts Doctrine's second-level cache: the store
 * keeps none); TrackingPolicySubscriber and the listeners' loadClassMetadata/resolveDiscriminator (Doctrine's mapping:
 * the description is the store's); IntlSubscriber's onQuery (TranslatableWalker: the finder joins the translations
 * itself); ExtensionSubscriber's entries of an EntityExtension (no extension is registered by omnibase, its bundles or
 * lesringostars: refused aloud when one is).
 */
final class OmnibaseListeners
{
    /** @var list<object> */
    private array $listeners = [];

    private function __construct()
    {
    }

    /** @param array<string, mixed> $options */
    public static function attach(EloquentUnitOfWork $unitOfWork, array $options = []): self
    {
        $self = new self();

        $extension = $options['entity_extension'] ?? null;
        if ($extension instanceof EntityExtension) {
            self::refuseExtensions($extension);
        } elseif ($extension instanceof \Closure) {
            // A host's (step F7d): asked at the first flush, once every service it is collected from can be made.
            $checked = false;
            $unitOfWork->listen(static function (object $event) use ($extension, &$checked): void {
                if (!$checked && $event instanceof \Omnibridge\Persistence\Lifecycle\PreFlush) {
                    $checked = true;
                    ($given = $extension()) instanceof EntityExtension && self::refuseExtensions($given);
                }
            });
        }

        $reader = $options['attribute_reader'] ?? (AttributeReader::hasInstance() ? AttributeReader::getInstance(false) : null);
        if ($reader instanceof AttributeReader || $reader instanceof \Closure) {
            $self->add($unitOfWork, AttributeRelay::attach($unitOfWork, $reader));
        }
        $self->add($unitOfWork, new IntlListener());
        if (\array_key_exists('empty_trash', $options)) {
            $self->add($unitOfWork, new TrasheableListener($options['empty_trash'] ?? '+7 days'));
        }
        if (\array_key_exists('max_revisions', $options)) {
            $self->add($unitOfWork, new VersionableListener((int) ($options['max_revisions'] ?? 5)));
        }
        if (isset($options['mention_enhancer'])) {
            $self->add($unitOfWork, new ThreadListener($options['mention_enhancer']));
        }
        if (isset($options['setting_bag'])) {
            $self->add($unitOfWork, new SettingListener($options['setting_bag']));
        }
        if (isset($options['translator'])) {
            $self->add($unitOfWork, new TextOverrideListener($options['translator']));
        }
        $dispatcher = $options['dispatcher'] ?? null;
        $entityDispatchers = $options['entity_dispatchers'] ?? [];
        if ($entityDispatchers instanceof \Closure || $entityDispatchers instanceof \Traversable) {
            // A host's own dispatchers (its container's services, made on this unit of work: step F7d), read at the
            // first entity event - they live as long as the container that holds this persistence.
            $self->add($unitOfWork, new EntityDispatchersListener($entityDispatchers, $unitOfWork));
            $entityDispatchers = [];
        }
        foreach ($entityDispatchers as $entityDispatcher) {
            if (\is_string($entityDispatcher)) {
                if (!$dispatcher instanceof EventDispatcherInterface) {
                    throw new \InvalidArgumentException('The entity dispatchers are made with the option "dispatcher", a PSR-14 event dispatcher.');
                }
                $requests = $options['requests'] ?? null;
                $entityDispatcher = new $entityDispatcher($dispatcher, new EntityHydrator($unitOfWork), $unitOfWork, $requests instanceof Requests ? $requests : null);
            }
            if (!$entityDispatcher instanceof AbstractEventDispatcher) {
                throw new \InvalidArgumentException(sprintf('An entity dispatcher is an %s, not a %s.', AbstractEventDispatcher::class, get_debug_type($entityDispatcher)));
            }
            $self->add($unitOfWork, new EntityDispatcherListener($entityDispatcher, $unitOfWork));
        }
        if (isset($options['user_subscriber'])) {
            $self->add($unitOfWork, new PreFlushListener($options['user_subscriber']));
        }
        if (($options['demo_lock'] ?? null) instanceof DemoAccountLock || ($options['demo_lock'] ?? null) instanceof \Closure) {
            $requests = $options['requests'] ?? null;
            $self->add($unitOfWork, new DemoAccountLockListener($options['demo_lock'], $requests instanceof Requests ? $requests : null));
        }

        return $self;
    }

    /** An EntityExtension holding an extension refused aloud: its entries are written on Doctrine only. */
    private static function refuseExtensions(EntityExtension $extension): void
    {
        if ([] !== $extension->getExtensions()) {
            throw UnsupportedPersistence::operation(
                'write the entries of an EntityExtension ('.implode(', ', array_keys($extension->getExtensions())).')',
                'omnibridge/doctrine\'s ExtensionSubscriber writes them on Doctrine only; no extension is registered by omnibase, its bundles or lesringostars'
            );
        }
    }

    /** @return list<object> the relay and the adapters, in the order they listen */
    public function listeners(): array
    {
        return $this->listeners;
    }

    /**
     * The adapter of that class, or null.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    public function get(string $class): ?object
    {
        foreach ($this->listeners as $listener) {
            if ($listener instanceof $class) {
                return $listener;
            }
        }

        return null;
    }

    private function add(EloquentUnitOfWork $unitOfWork, object $listener): void
    {
        if (!$listener instanceof AttributeRelay) {
            $unitOfWork->listen($listener(...));
        }
        $this->listeners[] = $listener;
    }
}
