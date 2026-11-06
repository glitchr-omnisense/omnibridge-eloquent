<?php

namespace Omnibridge\Eloquent\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Another joined subclass, with fields of its own. */
#[ORM\Entity]
class Page extends Thread
{
    #[ORM\Column(type: 'integer')]
    private int $position = 0;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTime $day = null;

    public function setPosition(int $position): static { $this->position = $position; return $this; }
    public function setDay(?\DateTime $day): static { $this->day = $day; return $this; }
}
