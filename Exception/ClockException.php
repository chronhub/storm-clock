<?php

declare(strict_types=1);

namespace Storm\Clock\Exception;

use RuntimeException;
use Storm\Contracts\Clock\ClockExceptionContract;

/**
 * Base of the Storm Clock exceptions; catch this to handle any clock-related error.
 *
 * Implements the contracted `ClockExceptionContract` on an SPL `RuntimeException`.
 */
class ClockException extends RuntimeException implements ClockExceptionContract
{
    public static function cannotLeaveUtc(): self
    {
        return new self(
            'PointInTime is always UTC; a timezone change cannot produce one. '
            .'For a local-time view, use DateTimeImmutable::createFromInterface($point)->setTimezone(...).'
        );
    }
}
