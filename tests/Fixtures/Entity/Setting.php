<?php

namespace Omnibridge\Eloquent\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;

/** An identifier the application gives (no generated value): written as it is, kept in the identity map at persist(). */
#[ORM\Entity]
class Setting
{
    #[ORM\Id, ORM\Column(type: 'string', length: 64)]
    private string $name;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $value;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $owner;

    public function __construct(string $name, User $owner, ?string $value = null)
    {
        $this->name = $name;
        $this->owner = $owner;
        $this->value = $value;
    }

    public function getName(): string { return $this->name; }
    public function getValue(): ?string { return $this->value; }
    public function setValue(?string $value): static { $this->value = $value; return $this; }
}
