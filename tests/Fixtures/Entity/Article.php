<?php

namespace Omnibridge\Eloquent\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;

/** A joined subclass: its own table, joined to the root's on the identifier. */
#[ORM\Entity]
class Article extends Thread
{
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $body = null;

    #[ORM\Column(type: 'json')]
    private array $meta = [];

    #[ORM\Column(type: 'simple_array', nullable: true)]
    private ?array $keywords = null;

    #[ORM\Column(type: 'boolean')]
    private bool $featured = false;

    public function setBody(?string $body): static { $this->body = $body; return $this; }
    public function getBody(): ?string { return $this->body; }
    public function setMeta(array $meta): static { $this->meta = $meta; return $this; }
    public function getMeta(): array { return $this->meta; }
    public function setKeywords(?array $keywords): static { $this->keywords = $keywords; return $this; }
    public function setFeatured(bool $featured): static { $this->featured = $featured; return $this; }
    public function isFeatured(): bool { return $this->featured; }
}
