<?php

declare(strict_types=1);

namespace Storm\Clock;

use Storm\Clock\Exception\InvalidDateTimeException;

/**
 * Central home of the canonical Storm clock datetime format and the assertion that enforces it:
 * RFC 3339 with explicit UTC timezone and 6-digit microsecond precision.
 */
final class ClockAssertion
{
    /**
     * Valid UTC timezone name variants.
     *
     * Kept here for external use such as tests and Doctrine types.
     */
    public const array UTC_VARIANTS = ['UTC', 'Z', '+00:00'];

    /**
     * Only `Z` and `+00:00` assert UTC affirmatively; RFC 3339 gives `-00:00` the opposite
     * meaning, that the local offset is unknown, so it is rejected with the non-UTC offsets.
     */
    public const string RFC3339_REGEX = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}(Z|\+00:00)\z/';

    /**
     * Assert that the given datetime string matches the Storm clock format: RFC 3339 with explicit
     * UTC timezone and 6-digit microsecond precision.
     *
     * | Datetime                           | Verdict                                 |
     * |------------------------------------|-----------------------------------------|
     * | `2024-01-01T10:00:00.000000Z`      | valid                                   |
     * | `2024-01-01T10:00:00.123456+00:00` | valid                                   |
     * | `2024-01-01T10:00:00Z`             | rejected, missing microseconds          |
     * | `2024-01-01T10:00:00.123Z`         | rejected, milliseconds not microseconds |
     * | `2024-01-01T10:00:00.000000`       | rejected, missing UTC designator        |
     * | `2024-01-01T10:00:00.000000-00:00` | rejected, unknown local offset          |
     * | `2024-01-01 10:00:00.000000Z`      | rejected, invalid separator             |
     *
     * @throws InvalidDateTimeException
     */
    public static function assertFormat(string $datetime): void
    {
        if (! preg_match(self::RFC3339_REGEX, $datetime)) {
            throw InvalidDateTimeException::invalidFormat(
                got: $datetime,
                expected: PointInTime::FORMAT,
            );
        }
    }
}
