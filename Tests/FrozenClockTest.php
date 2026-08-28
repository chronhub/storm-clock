<?php

declare(strict_types=1);

namespace Storm\Clock\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Clock\Exception\InvalidDateTimeException;
use Storm\Clock\FrozenClock;
use Storm\Clock\PointInTime;

final class FrozenClockTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Construction
    // -------------------------------------------------------------------------

    #[Test]
    public function creates_from_valid_string(): void
    {
        $clock = FrozenClock::at('2024-01-01T10:00:00.000000Z');

        $this->assertSame(PointInTime::class, $clock->now()::class);
    }

    #[Test]
    public function creates_from_now(): void
    {
        $clock = FrozenClock::fromNow();

        $this->assertSame(PointInTime::class, $clock->now()::class);
        $this->assertSame(6, strlen($clock->now()->format('u')));
    }

    #[Test]
    public function throws_for_invalid_format(): void
    {
        $this->expectException(InvalidDateTimeException::class);

        FrozenClock::at('2024-01-01T10:00:00Z');
    }

    #[Test]
    #[Group('adversarial')]
    public function advance_rejects_a_non_positive_amount(): void
    {
        // the runtime guard on PointInTime arithmetic reaches the clock transitively
        $clock = FrozenClock::at('2024-01-01T10:00:00.000000Z');

        $this->expectException(InvalidDateTimeException::class);

        $clock->advance(0); // @phpstan-ignore argument.type (hostile on purpose: the guard under test)
    }

    #[Test]
    public function throws_for_non_utc(): void
    {
        $this->expectException(InvalidDateTimeException::class);

        FrozenClock::at('2024-01-01T10:00:00.000000+02:00');
    }

    #[Test]
    #[Group('adversarial')]
    public function at_refuses_the_relative_now(): void
    {
        // accepted, 'now' would freeze the clock on the real instant of construction and cost the
        // determinism the class exists for; fromNow() is that exit, named
        $this->expectException(InvalidDateTimeException::class);

        FrozenClock::at('now');
    }

    #[Test]
    #[Group('adversarial')]
    public function reset_refuses_the_relative_now(): void
    {
        // worse than at(): accepted mid-scenario, 'now' would UNFREEZE a running clock
        $clock = FrozenClock::at('2024-01-01T10:00:00.000000Z');

        $this->expectException(InvalidDateTimeException::class);

        $clock->reset('now');
    }

    #[Test]
    #[Group('adversarial')]
    public function move_to_refuses_the_relative_now(): void
    {
        $clock = FrozenClock::at('2024-01-01T10:00:00.000000Z');

        $this->expectException(InvalidDateTimeException::class);

        $clock->moveTo('now');
    }

    // -------------------------------------------------------------------------
    // now(): frozen
    // -------------------------------------------------------------------------

    #[Test]
    public function now_returns_frozen_point(): void
    {
        $clock = FrozenClock::at('2024-01-01T10:00:00.000000Z');

        $this->assertSame('10:00:00', $clock->now()->format('H:i:s'));
    }

    #[Test]
    public function now_always_returns_same_instant(): void
    {
        $clock = FrozenClock::at('2024-01-01T10:00:00.000000Z');

        $this->assertTrue($clock->now()->equals($clock->now()));
    }

    // -------------------------------------------------------------------------
    // advance()
    // -------------------------------------------------------------------------

    #[Test]
    public function advances_by_seconds(): void
    {
        $clock = FrozenClock::at('2024-01-01T10:00:00.000000Z');
        $clock->advance(30);

        $this->assertSame('10:00:30', $clock->now()->format('H:i:s'));
    }

    #[Test]
    public function advances_by_minutes(): void
    {
        $clock = FrozenClock::at('2024-01-01T10:00:00.000000Z');
        $clock->advanceMinutes(15);

        $this->assertSame('10:15:00', $clock->now()->format('H:i:s'));
    }

    #[Test]
    public function advances_by_hours(): void
    {
        $clock = FrozenClock::at('2024-01-01T10:00:00.000000Z');
        $clock->advanceHours(2);

        $this->assertSame('12:00:00', $clock->now()->format('H:i:s'));
    }

    #[Test]
    public function advance_is_cumulative(): void
    {
        $clock = FrozenClock::at('2024-01-01T10:00:00.000000Z');
        $clock->advance(30);
        $clock->advance(30);

        $this->assertSame('10:01:00', $clock->now()->format('H:i:s'));
    }

    // -------------------------------------------------------------------------
    // rewind()
    // -------------------------------------------------------------------------

    #[Test]
    public function rewinds_by_seconds(): void
    {
        $clock = FrozenClock::at('2024-01-01T10:00:30.000000Z');
        $clock->rewind(30);

        $this->assertSame('10:00:00', $clock->now()->format('H:i:s'));
    }

    #[Test]
    public function rewind_is_cumulative(): void
    {
        $clock = FrozenClock::at('2024-01-01T10:01:00.000000Z');
        $clock->rewind(30);
        $clock->rewind(30);

        $this->assertSame('10:00:00', $clock->now()->format('H:i:s'));
    }

    // -------------------------------------------------------------------------
    // moveTo() / reset()
    // -------------------------------------------------------------------------

    #[Test]
    public function moves_to_new_instant(): void
    {
        $clock = FrozenClock::at('2024-01-01T10:00:00.000000Z');
        $clock->advance(3600);
        $clock->moveTo('2024-06-01T08:00:00.000000Z');

        $this->assertSame('2024-06-01', $clock->now()->format('Y-m-d'));
        $this->assertSame('08:00:00', $clock->now()->format('H:i:s'));
    }

    #[Test]
    public function move_to_throws_for_invalid_format(): void
    {
        $this->expectException(InvalidDateTimeException::class);

        $clock = FrozenClock::at('2024-01-01T10:00:00.000000Z');
        $clock->moveTo('2024-06-01T08:00:00Z');
    }

    // -------------------------------------------------------------------------
    // advance + rewind combined
    // -------------------------------------------------------------------------

    #[Test]
    public function advance_then_rewind_returns_to_origin(): void
    {
        $clock = FrozenClock::at('2024-01-01T10:00:00.000000Z');
        $original = $clock->now()->format('H:i:s');

        $clock->advance(60);
        $clock->rewind(60);

        $this->assertSame($original, $clock->now()->format('H:i:s'));
    }
}
