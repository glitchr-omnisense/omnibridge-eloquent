<?php

namespace Omnibridge\Eloquent\Tests\Fixtures\Callbacks;

use Doctrine\ORM\Mapping as ORM;

/**
 * An entity with a callback of each moment, as omnipack/restaurant's #[PreUpdate] touch(): a field the PreUpdate
 * callback changes that the change set did not hold, written all the same (recomputed after the callbacks).
 */
#[ORM\Entity]
#[ORM\Table(name: 'ticket')]
#[ORM\HasLifecycleCallbacks]
class Ticket extends Stamped
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'integer')]
    private ?int $id = null; // @phpstan-ignore property.unusedType (the database gives it)

    #[ORM\Column(type: 'string', length: 64)]
    private string $title;

    #[ORM\Column(type: 'integer')]
    private int $revision = 0;

    #[ORM\Column(type: 'integer')]
    private int $flushes = 0;

    public function __construct(string $title)
    {
        $this->title = $title;
    }

    public function getId(): ?int { return $this->id; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): static { $this->title = $title; return $this; }
    public function getRevision(): int { return $this->revision; }
    public function label(): string { return $this->title; }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function touch(): void
    {
        ++$this->revision;
        self::$calls[] = 'touch '.$this->title.' '.$this->revision;
    }

    #[ORM\PreFlush]
    public function flushing(): void
    {
        ++$this->flushes;
        self::$calls[] = 'flushing '.$this->title;
    }

    #[ORM\PostPersist, ORM\PostUpdate, ORM\PreRemove, ORM\PostRemove, ORM\PostLoad]
    public function after(object $arguments): void
    {
        // Doctrine's arguments (PostPersistEventArgs) and omnibridge's events (PostPersist) under one name.
        self::$calls[] = 'after '.$this->title.' '.preg_replace('/EventArgs$/', '', (new \ReflectionClass($arguments))->getShortName());
    }
}
