<?php

namespace Omnibridge\Eloquent\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Dog extends Animal
{
    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $trained = null;

    public function setTrained(?bool $trained): static { $this->trained = $trained; return $this; }
}
