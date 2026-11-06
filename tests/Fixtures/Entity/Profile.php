<?php

namespace Omnibridge\Eloquent\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;

/** A one-to-one's owning side; User holds the inverse side, read at once. */
#[ORM\Entity]
class Profile
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class, inversedBy: 'profile')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $bio = null;

    public function __construct(?User $user = null, ?string $bio = null)
    {
        $this->user = $user;
        $this->bio = $bio;
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): ?User { return $this->user; }
}
