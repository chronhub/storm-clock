<?php

declare(strict_types=1);

namespace Storm\Clock;

use Psr\Clock\ClockInterface;
use Storm\Contracts\Clock\Clock;

/**
 * Storm system clock that decorates any PSR-20 `ClockInterface`.
 *
 * Normalizes the datetime to RFC 3339 with microseconds and UTC timezone
 * before creating a `PointInTime`, guaranteeing the format is always valid.
 *
 * Use `FrozenClock` for tests.
 *
 * @implements Clock<PointInTime>
 */
final readonly class SystemClock implements Clock
{
    public function __construct(
        private ClockInterface $inner,
    ) {}

    public function now(): PointInTime
    {
        // fromDateTime drops to the base class before converting, so an inner clock that already
        // yields a PointInTime, such as a FrozenClock or another SystemClock, is normalized, not rejected
        // by PointInTime's setTimezone() guard.
        return PointInTime::fromDateTime($this->inner->now());
    }
}
