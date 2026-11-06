<?php

namespace Omnibridge\Eloquent\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Cat extends Animal
{
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $lives = 9;
}
