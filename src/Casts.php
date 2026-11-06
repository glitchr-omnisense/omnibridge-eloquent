<?php

namespace Omnibridge\Eloquent;

use Omnibridge\Schema\Database\ColumnTypes;

/**
 * A column's value as the entity holds it, and back: the conversions Doctrine's
 * DBAL types make, by the type's name a description gives (Omnibridge\Schema\Field::$type),
 * without DBAL.
 *
 * - the portable types: integer, smallint and bigint (an int, a bigint past
 *   PHP_INT_MAX a string), boolean, float, decimal (a string), string, text,
 *   ascii_string and guid, json (decoded to arrays), simple_array (NULL read
 *   as [] as DBAL reads it), the dates
 *   (datetime, datetimetz, date, time and their _immutable, in PHP's
 *   default zone as DBAL reads them), binary and blob (kept as they come);
 * - utc(): datetime and datetimetz read and written in UTC, and utc_datetime,
 *   utc_datetime_immutable - omnibridge/doctrine's Types::register(), which
 *   omnibase sites run on;
 * - an application's own types: a conversion given by name (with()), else what
 *   its ColumnTypes say - an alias followed to its portable type, an
 *   enumeration's value as the string it is, a set's values as a list.
 *
 * A type nothing knows is refused, never read as a raw string silently.
 */
final class Casts
{
    private const DATETIME = 'Y-m-d H:i:s';

    /** @var array<string, \Closure(mixed): mixed> */
    private array $toPhp = [];

    /** @var array<string, \Closure(mixed): mixed> */
    private array $toDatabase = [];

    /** @var array<string, true> the types given whose conversion reads null too */
    private array $readsNull = [];

    public function __construct(
        private readonly ?ColumnTypes $columnTypes = null,
        private readonly bool $utc = false,
    ) {
    }

    /** The conversions of an omnibase site: datetime and datetimetz in UTC, the utc_ types (omnibridge/doctrine's Types::register()). */
    public static function utc(?ColumnTypes $columnTypes = null): self
    {
        return new self($columnTypes, true);
    }

    /**
     * The same conversions, plus a type of the application's own (or one replaced).
     *
     * @param \Closure(mixed): mixed      $toPhp      the column's value (never null) => the entity's
     * @param (\Closure(mixed): mixed)|null $toDatabase the entity's value (never null) => the column's; the value as it is without
     * @param bool                        $readsNull  whether $toPhp is given null too (a set reads NULL as no value: [])
     */
    public function with(string $type, \Closure $toPhp, ?\Closure $toDatabase = null, bool $readsNull = false): self
    {
        $casts = clone $this;
        $casts->toPhp[$type] = $toPhp;
        $casts->toDatabase[$type] = $toDatabase ?? static fn (mixed $value): mixed => $value;
        if ($readsNull) {
            $casts->readsNull[$type] = true;
        } else {
            unset($casts->readsNull[$type]);
        }

        return $casts;
    }

    public function columnTypes(): ?ColumnTypes
    {
        return $this->columnTypes;
    }

    /** Whether a column of that type is read as something else than it comes (a date, a decoded array...). */
    public function knows(string $type): bool
    {
        try {
            $this->resolve($type);

            return true;
        } catch (\DomainException) {
            return false;
        }
    }

    /** The column's value as the entity holds it. */
    public function toPhp(string $type, mixed $value): mixed
    {
        if (null === $value) {
            // As DBAL's simple_array and omnibase's sets: NULL is no value, an empty list.
            return match (true) {
                isset($this->readsNull[$type]) => ($this->toPhp[$type])(null),
                isset($this->toPhp[$type]) => null,
                default => \in_array($this->resolve($type), ['simple_array', 'set'], true) ? [] : null,
            };
        }
        if (isset($this->toPhp[$type])) {
            return ($this->toPhp[$type])($value);
        }
        if (\is_resource($value)) {
            $value = stream_get_contents($value);
        }

        return match ($resolved = $this->resolve($type)) {
            'integer', 'smallint' => (int) $value,
            'bigint' => \is_int($value) ? $value : ((string) (int) $value === (string) $value ? (int) $value : (string) $value),
            'boolean' => (bool) $value,
            'float' => (float) $value,
            'decimal', 'string', 'text', 'ascii_string', 'guid', 'enum' => (string) $value,
            'json' => '' === $value ? null : json_decode((string) $value, true, 512, \JSON_THROW_ON_ERROR),
            'simple_array', 'set' => '' === $value ? [] : explode(',', (string) $value),
            'datetime', 'datetimetz' => $this->utc ? self::utcDateTime((string) $value) : self::date(\DateTime::class, self::DATETIME, (string) $value),
            'utc_datetime' => self::utcDateTime((string) $value),
            'utc_datetime_immutable' => \DateTimeImmutable::createFromMutable(self::utcDateTime((string) $value)),
            'datetime_immutable', 'datetimetz_immutable' => self::date(\DateTimeImmutable::class, self::DATETIME, (string) $value),
            'date' => self::date(\DateTime::class, '!Y-m-d', (string) $value),
            'date_immutable' => self::date(\DateTimeImmutable::class, '!Y-m-d', (string) $value),
            'time' => self::date(\DateTime::class, '!H:i:s', (string) $value),
            'time_immutable' => self::date(\DateTimeImmutable::class, '!H:i:s', (string) $value),
            'binary', 'blob' => $value,
            default => throw new \DomainException(\sprintf('No conversion for the column type "%s" (%s).', $type, $resolved)),
        };
    }

    /** The entity's value as the column keeps it: what a criterion binds. */
    public function toDatabase(string $type, mixed $value): mixed
    {
        if (null === $value) {
            return null;
        }
        if (isset($this->toDatabase[$type])) {
            return ($this->toDatabase[$type])($value);
        }

        return match ($this->resolve($type)) {
            'integer', 'smallint' => \is_int($value) ? $value : (int) $value,
            'boolean' => $value ? 1 : 0,
            'json' => json_encode($value, \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR),
            'simple_array', 'set' => implode(',', (array) $value),
            'datetime', 'datetimetz' => $value instanceof \DateTimeInterface ? ($this->utc ? self::inUtc($value) : $value)->format(self::DATETIME) : $value,
            'utc_datetime', 'utc_datetime_immutable' => $value instanceof \DateTimeInterface ? self::inUtc($value)->format(self::DATETIME) : $value,
            'datetime_immutable', 'datetimetz_immutable' => $value instanceof \DateTimeInterface ? $value->format(self::DATETIME) : $value,
            'date', 'date_immutable' => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value,
            'time', 'time_immutable' => $value instanceof \DateTimeInterface ? $value->format('H:i:s') : $value,
            default => $value instanceof \BackedEnum ? $value->value : ($value instanceof \Stringable ? (string) $value : $value),
        };
    }

    /** The type a value is converted as: the type itself when known, else its alias followed, else 'enum' or 'set' for the application's ColumnTypes. */
    private function resolve(string $type): string
    {
        static $portable = ['integer', 'smallint', 'bigint', 'boolean', 'float', 'decimal', 'string', 'text', 'ascii_string', 'guid',
            'json', 'simple_array', 'datetime', 'datetimetz', 'utc_datetime', 'utc_datetime_immutable', 'datetime_immutable',
            'datetimetz_immutable', 'date', 'date_immutable', 'time', 'time_immutable', 'binary', 'blob'];
        if (isset($this->toPhp[$type]) || \in_array($type, $portable, true)) {
            return $type;
        }
        if (null !== $this->columnTypes) {
            $seen = [];
            for ($name = $type; isset($this->columnTypes->aliases[$name]) && !isset($seen[$name]); $name = $this->columnTypes->aliases[$name]) {
                $seen[$name] = true;
            }
            if (isset($this->columnTypes->enumerations[$name])) {
                return 'enum';
            }
            if (isset($this->columnTypes->sets[$name])) {
                return 'set';
            }
            if ($name !== $type && \in_array($name, $portable, true)) {
                return $name;
            }
        }

        throw new \DomainException(\sprintf('No conversion for the column type "%s": give it with Casts::with() or in the ColumnTypes.', $type));
    }

    private static function utcDateTime(string $value): \DateTime
    {
        $zone = new \DateTimeZone('UTC');

        return \DateTime::createFromFormat(self::DATETIME, $value, $zone)
            ?: (date_create($value, $zone) ?: throw new \UnexpectedValueException(\sprintf('"%s" is no date and time.', $value)));
    }

    /**
     * @template T of \DateTime|\DateTimeImmutable
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private static function date(string $class, string $format, string $value): \DateTimeInterface
    {
        $date = $class::createFromFormat($format, $value);
        if (false === $date && self::DATETIME === $format) {
            // As DBAL's date and time types: a value with microseconds or a zone, read by date_create().
            $date = \DateTime::class === $class ? date_create($value) : date_create_immutable($value);
        }

        return $date ?: throw new \UnexpectedValueException(\sprintf('"%s" is no %s (%s).', $value, $class, $format));
    }

    private static function inUtc(\DateTimeInterface $value): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'));
    }
}
