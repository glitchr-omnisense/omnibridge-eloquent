<?php

namespace Omnibridge\Eloquent\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;

/** A one-to-many's owning side, indexed by its locale on the thread. */
#[ORM\Entity]
class Translation
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Thread::class, inversedBy: 'translations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Thread $thread;

    #[ORM\Column(type: 'string', length: 8)]
    private string $locale;

    #[ORM\Column(type: 'string', length: 255)]
    private string $title;

    public function __construct(Thread $thread, string $locale, string $title)
    {
        $this->thread = $thread;
        $this->locale = $locale;
        $this->title = $title;
    }

    public function getId(): ?int { return $this->id; }
    public function getLocale(): string { return $this->locale; }
    public function getTitle(): string { return $this->title; }
}
