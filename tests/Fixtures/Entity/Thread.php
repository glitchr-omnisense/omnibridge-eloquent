<?php

namespace Omnibridge\Eloquent\Tests\Fixtures\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/** A joined hierarchy's root: its own rows, Article's and Page's, the discriminator saying which. */
#[ORM\Entity]
#[ORM\InheritanceType('JOINED')]
#[ORM\DiscriminatorColumn(name: 'kind', type: 'string', length: 32)]
#[ORM\DiscriminatorMap(['thread' => Thread::class, 'article' => Article::class, 'page' => Page::class])]
class Thread
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'integer')]
    protected ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    protected string $title = '';

    #[ORM\Column(type: 'datetime')]
    protected \DateTime $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    protected ?\DateTimeImmutable $publishedAt = null;

    #[ORM\ManyToOne(targetEntity: Thread::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    protected ?Thread $parent = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'threads')]
    protected ?User $author = null;

    /** @var Collection<int, Tag> */
    #[ORM\ManyToMany(targetEntity: Tag::class, inversedBy: 'threads')]
    protected Collection $tags;

    /** @var Collection<string, Translation> */
    #[ORM\OneToMany(targetEntity: Translation::class, mappedBy: 'thread', indexBy: 'locale', cascade: ['persist', 'remove'], orphanRemoval: true)]
    protected Collection $translations;

    public function __construct(string $title = '')
    {
        $this->title = $title;
        $this->createdAt = new \DateTime('2026-11-07 10:00:00', new \DateTimeZone('UTC'));
        $this->tags = new ArrayCollection();
        $this->translations = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): static { $this->title = $title; return $this; }
    public function setParent(?Thread $parent): static { $this->parent = $parent; return $this; }
    public function getParent(): ?Thread { return $this->parent; }
    public function setAuthor(?User $author): static { $this->author = $author; return $this; }
    public function getAuthor(): ?User { return $this->author; }
    public function setPublishedAt(?\DateTimeImmutable $at): static { $this->publishedAt = $at; return $this; }
    public function getPublishedAt(): ?\DateTimeImmutable { return $this->publishedAt; }
    public function getCreatedAt(): \DateTime { return $this->createdAt; }
    /** @return Collection<int, Tag> */
    public function getTags(): Collection { return $this->tags; }
    public function addTag(Tag $tag): static { $this->tags->add($tag); return $this; }
    public function removeTag(Tag $tag): static { $this->tags->removeElement($tag); return $this; }
    /** @param Collection<int, Tag> $tags */
    public function setTags(Collection $tags): static { $this->tags = $tags; return $this; }
    /** @return Collection<string, Translation> */
    public function getTranslations(): Collection { return $this->translations; }
    public function addTranslation(Translation $translation): static { $this->translations->set($translation->getLocale(), $translation); return $this; }
    public function removeTranslation(string $locale): static { $this->translations->remove($locale); return $this; }
}
