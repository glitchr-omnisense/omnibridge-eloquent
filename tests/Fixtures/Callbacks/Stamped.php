<?php

namespace Omnibridge\Eloquent\Tests\Fixtures\Callbacks;

use Doctrine\ORM\Mapping as ORM;

/** A mapped superclass whose callbacks every entity below it inherits, before its own: each call recorded. */
#[ORM\MappedSuperclass]
#[ORM\HasLifecycleCallbacks]
abstract class Stamped
{
    /** @var list<string> every callback called, in order, on either backend */
    public static array $calls = [];

    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    protected ?string $createdBy = null;

    #[ORM\PrePersist]
    public function created(): void
    {
        $this->createdBy = 'callback';
        self::$calls[] = 'created '.$this->label();
    }

    abstract public function label(): string;
}
