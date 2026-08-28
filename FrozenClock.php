<?php

declare(strict_types=1);

namespace Storm\Clock;

use Storm\Clock\Exception\InvalidDateTimeException;
use Storm\Contracts\Clock\Clock;

/**
 * Deterministic clock for testing: freezes time at a given instant; use advance/rewind to simulate
 * time passing.
 *
 * ```php
 * $clock = FrozenClock::at('2024-01-01T10:00:00.000000Z');
 * $clock->now();      // 2024-01-01T10:00:00.000000Z
 * $clock->advance(30);
 * $clock->now();      // 2024-01-01T10:00:30.000000Z
 * ```
 *
 * @implements Clock<PointInTime>
 */
final class FrozenClock implements Clock
{
    private function __construct(private PointInTime $frozen) {}

    /**
     * Create a frozen clock at the given datetime string.
     *
     * Must be RFC 3339 with explicit UTC and microsecond precision; the relative `now` is refused,
     * since an accepted one would freeze the clock on the real instant of construction and cost the
     * determinism the class exists for. `fromNow()` is that exit, named.
     *
     * @throws InvalidDateTimeException
     */
    public static function at(string $datetime): self
    {
        return new self(PointInTime::from($datetime));
    }

    /**
     * Create a frozen clock at the current time.
     */
    public static function fromNow(): self
    {
        return new self(new PointInTime('now'));
    }

    public function now(): PointInTime
    {
        return $this->frozen;
    }

    /**
     * Advance the frozen clock by N seconds.
     *
     * @param  int<1,max>  $seconds
     *
     * @throws InvalidDateTimeException
     */
    public function advance(int $seconds): void
    {
        $this->frozen = $this->frozen->addSeconds($seconds);
    }

    /**
     * Advance the frozen clock by N minutes.
     *
     * @param  int<1,max>  $minutes
     *
     * @throws InvalidDateTimeException
     */
    public function advanceMinutes(int $minutes): void
    {
        $this->frozen = $this->frozen->addMinutes($minutes);
    }

    /**
     * Advance the frozen clock by N hours.
     *
     * @param  int<1,max>  $hours
     *
     * @throws InvalidDateTimeException
     */
    public function advanceHours(int $hours): void
    {
        $this->frozen = $this->frozen->addHours($hours);
    }

    /**
     * Rewind the frozen clock by N seconds.
     *
     * @param  int<1,max>  $seconds
     *
     * @throws InvalidDateTimeException
     */
    public function rewind(int $seconds): void
    {
        $this->frozen = $this->frozen->subSeconds($seconds);
    }

    /**
     * Reset the clock to a new instant.
     *
     * The same canonical-string contract as `at()`: the relative `now` is refused, since accepting
     * it here would UNFREEZE a clock mid-scenario onto the real instant of the call.
     *
     * @throws InvalidDateTimeException
     */
    public function reset(string $datetime): void
    {
        $this->frozen = PointInTime::from($datetime);
    }

    /**
     * Move the clock to a new instant, an alias for `reset()`.
     *
     * @throws InvalidDateTimeException
     */
    public function moveTo(string $datetime): void
    {
        $this->reset($datetime);
    }
}
