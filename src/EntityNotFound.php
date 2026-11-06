<?php

namespace Omnibridge\Eloquent;

/**
 * A lazy entity (a reference, an association's ghost) whose row is not there when it is first used - deleted
 * since, or left out by a scope -, as Doctrine's EntityNotFoundException says it of a proxy.
 */
final class EntityNotFound extends \RuntimeException
{
    public static function of(string $class, mixed $id): self
    {
        return new self(\sprintf('The %s of identifier %s was not found (deleted, or left out by a scope).', $class, json_encode($id, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)));
    }
}
