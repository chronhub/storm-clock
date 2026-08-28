<?php

declare(strict_types=1);

namespace Storm\Clock\Exception;

use Storm\Clock\PointInTime;
use Throwable;

/**
 * Thrown when a datetime value is not acceptable to the Storm clock: either it cannot be parsed at
 * all, or it parses but does not match the expected RFC 3339 shape with explicit UTC timezone and
 * microsecond precision.
 *
 * This is the single exception leaving {@see PointInTime}. The native
 * {@see \DateMalformedStringException} is wrapped here and stays reachable through `getPrevious()`,
 * so consumers declare and catch one domain type instead of two unrelated hierarchies.
 *
 * Expected format: `Y-m-d\TH:i:s.uP`, for example `2024-01-01T10:00:00.000000+00:00`.
 */
final class InvalidDateTimeException extends ClockException
{
    /**
     * The string parses but is not in the canonical Storm clock format.
     */
    public static function invalidFormat(string $got, string $expected, ?Throwable $previous = null): self
    {
        return new self(
            "Invalid datetime format. Expected '$expected', got '$got'.",
            previous: $previous,
        );
    }

    /**
     * The value cannot be parsed at all; wraps the native failure, typically a
     * `DateMalformedStringException` or a `DateRangeError`.
     */
    public static function unparseable(string $got, Throwable $previous): self
    {
        return new self(
            "Malformed datetime, cannot be parsed: '$got'.",
            previous: $previous,
        );
    }

    /**
     * The string has a valid shape but an out-of-range component, such as Feb 30 or hour 24, that the
     * lenient parser would silently roll over to a different instant.
     */
    public static function outOfRange(string $got): self
    {
        return new self("Datetime '$got' is out of range — it would silently roll over to a different instant.");
    }

    /**
     * A computed instant left the storable year range 0001-9999: really typed `PointInTime`, yet
     * `from()` and `fromStorage()` both refuse the form; persisted, it could never be rehydrated.
     * The lie dies at computation, loudly, never at the read: a saga timer written with a
     * five-digit year would refuse to hydrate.
     */
    public static function unstorable(string $got): self
    {
        return new self("Computed datetime '$got' left the storable year range 0001-9999 — it could never be read back by from()/fromStorage(); bound the arithmetic amount.");
    }

    /**
     * The arithmetic amount is not a positive integer: zero would silently no-op, and a negative
     * value would silently invert the operation's direction.
     */
    public static function nonPositiveAmount(int $amount): self
    {
        return new self("Datetime arithmetic expects a positive amount of units, got $amount.");
    }
}
