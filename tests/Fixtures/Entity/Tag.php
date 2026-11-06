<?php

namespace Omnibridge\Eloquent\Tests\Fixtures\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/** The inverse side of a many-to-many. */
#[ORM\Entity]
class Tag
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 64)]
    private string $name;

    /** @var Collection<int, Thread> */
    #[ORM\ManyToMany(targetEntity: Thread::class, mappedBy: 'tags')]
    private Collection $threads;

    public function __construct(string $name = '')
    {
        $this->name = $name;
        $this->threads = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    /** @return Collection<int, Thread> */
    public function getThreads(): Collection { return $this->threads; }
}
