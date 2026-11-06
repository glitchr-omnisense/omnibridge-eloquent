<?php

namespace Omnibridge\Eloquent\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;

/** A single-table hierarchy: one table, the discriminator naming the class. */
#[ORM\Entity]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'species', type: 'string', length: 16)]
#[ORM\DiscriminatorMap(['animal' => Animal::class, 'cat' => Cat::class, 'dog' => Dog::class])]
class Animal
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'integer')]
    protected ?int $id = null;

    #[ORM\Column(type: 'string', length: 64)]
    protected string $name;

    #[ORM\ManyToOne(targetEntity: User::class)]
    protected ?User $owner = null;

    public function __construct(string $name = '', ?User $owner = null)
    {
        $this->name = $name;
        $this->owner = $owner;
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
}
