<?php

namespace Omnibridge\Eloquent\Tests\Fixtures\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/** No inheritance: an association to it is a lazy ghost; its threads a one-to-many ordered by title. */
#[ORM\Entity]
class User
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 180, unique: true)]
    private string $email;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $score = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $balance = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private int|string|null $visits = null;

    #[ORM\OneToOne(targetEntity: Profile::class, mappedBy: 'user', cascade: ['persist'])]
    private ?Profile $profile = null;

    /** @var Collection<int, Thread> */
    #[ORM\OneToMany(targetEntity: Thread::class, mappedBy: 'author')]
    #[ORM\OrderBy(['title' => 'ASC'])]
    private Collection $threads;

    public function __construct(string $email = '')
    {
        $this->email = $email;
        $this->threads = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getEmail(): string { return $this->email; }
    public function setScore(?float $score): static { $this->score = $score; return $this; }
    public function setBalance(?string $balance): static { $this->balance = $balance; return $this; }
    public function setVisits(int|string|null $visits): static { $this->visits = $visits; return $this; }
    public function getProfile(): ?Profile { return $this->profile; }
    public function setProfile(?Profile $profile): static { $this->profile = $profile; return $this; }
    public function setEmail(string $email): static { $this->email = $email; return $this; }
    /** @return Collection<int, Thread> */
    public function getThreads(): Collection { return $this->threads; }
}
