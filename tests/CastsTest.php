<?php

namespace Omnibridge\Eloquent\Tests;

use Omnibridge\Eloquent\Casts;
use PHPUnit\Framework\TestCase;

/** One time: an omnibase site's casts (Casts::utc()) write and read every date and time type in UTC, immutable, as Doctrine's. */
final class CastsTest extends TestCase
{
    public function testEveryDateTimeTypeIsWrittenAndReadInUtc(): void
    {
        $casts = Casts::utc();
        $paris = new \DateTimeImmutable('2026-10-18 09:30:00', new \DateTimeZone('Europe/Paris'));
        foreach (['datetime', 'datetimetz', 'datetime_immutable', 'datetimetz_immutable', 'utc_datetime', 'utc_datetime_immutable'] as $type) {
            $value = str_contains($type, 'immutable') ? $paris : \DateTime::createFromImmutable($paris);
            self::assertSame('2026-10-18 07:30:00', $casts->toDatabase($type, $value), $type);
            $read = $casts->toPhp($type, '2026-10-18 07:30:00');
            self::assertInstanceOf(\DateTimeInterface::class, $read);
            self::assertSame('2026-10-18T07:30:00+00:00', $read->format(\DATE_ATOM), $type);
            // omnibridge/doctrine 4.0: every one of them is read as a \DateTimeImmutable (a \DateTime is still written).
            self::assertInstanceOf(\DateTimeImmutable::class, $read, $type);
        }
    }
}
