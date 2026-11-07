<?php

namespace Omnibridge\Eloquent\Tests\Support;

use Base\Attributes\AttributeReader;
use Base\EntityDispatcher\AbstractEventDispatcher;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Omnibridge\Contract\Requests;
use Omnibridge\Eloquent\Omnibase\EloquentPersistence;
use Psr\Container\ContainerInterface;

/**
 * omnibridge/eloquent beside an omnibase application's Doctrine, with omnibase's lifecycle (plan "omnibase agnostic",
 * step F7c): the persistence on Doctrine's PDO (FinderParity::persistence(): the same database, the same scopes), its
 * unit of work given the behaviours' relay and the adapters of the listeners Doctrine runs, each wired from the
 * application's container as omnibridge/symfony wires Doctrine's - what a host does at step F7d. The core's behaviour
 * tests ask it for their Eloquent side (the harness switch, OMNIBASE_ELOQUENT), the behaviour parity tests too.
 */
final class OmnibaseHarness
{
    /** @param array<string, mixed> $lifecycle OmnibaseListeners' options over the container's */
    public static function persistence(ContainerInterface $container, array $lifecycle = []): EloquentPersistence
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get('doctrine')->getManager();

        return FinderParity::persistence($entityManager, (string) $container->getParameter('kernel.project_dir'), [
            'lifecycle' => $lifecycle + self::lifecycle($container, $entityManager),
        ]);
    }

    /** @return array<string, mixed> OmnibaseListeners' options, from the container */
    public static function lifecycle(ContainerInterface $container, EntityManagerInterface $entityManager): array
    {
        $parameter = static fn (string $name, mixed $default): mixed => method_exists($container, 'hasParameter') && $container->hasParameter($name) ? $container->getParameter($name) : $default;
        $service = static fn (string $id): ?\Closure => $container->has($id) ? static fn () => $container->get($id) : null;

        // The entity dispatchers Doctrine calls (the event manager's listeners), each made again on the Eloquent unit
        // of work: the core's (user.*, thread.*) and the application's (lesringostars' newsletter.*).
        $dispatchers = [];
        foreach ([Events::prePersist, Events::postPersist] as $event) {
            foreach ($entityManager->getEventManager()->getListeners($event) as $listener) {
                if ($listener instanceof AbstractEventDispatcher) {
                    $dispatchers[$listener::class] = $listener::class;
                }
            }
        }

        return array_filter([
            'attribute_reader' => AttributeReader::hasInstance() ? AttributeReader::getInstance(false) : null,
            'empty_trash' => $parameter('base.extension.empty_trash', '+7 days'),
            'max_revisions' => (int) $parameter('base.extension.max_revisions', 5),
            'mention_enhancer' => $service('Base\Service\Model\Wysiwyg\MentionEnhancerInterface'),
            'setting_bag' => $service('Base\Service\SettingBagInterface'),
            'translator' => $service('Base\Translation\OverridingTranslator'),
            'dispatcher' => $container->get('event_dispatcher'),
            'requests' => $container->has(Requests::class) ? $container->get(Requests::class) : null,
            'entity_dispatchers' => array_values($dispatchers),
            'user_subscriber' => $service('Base\EntitySubscriber\UserSubscriber'),
            'entity_extension' => $container->has('base.entity_extension') ? $container->get('base.entity_extension') : null,
        ], static fn (mixed $value): bool => null !== $value);
    }
}
