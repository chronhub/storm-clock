<?php

declare(strict_types=1);

namespace Storm\Clock\Tests;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Clock\ClockAssertion;
use Storm\Clock\FrozenClock;
use Storm\Clock\PointInTime;
use Storm\Clock\SystemClock;
use Symfony\Component\Clock\Clock as SymfonyClock;
use Symfony\Component\Clock\MockClock;

final class SystemClockTest extends TestCase
{
    #[Test]
    public function returns_point_in_time(): void
    {
        $clock = new SystemClock(new MockClock('2024-01-01T10:00:00.000000Z'));

        $this->assertSame(PointInTime::class, $clock->now()::class);
    }

    #[Test]
    public function returns_utc_variant_timezone(): void
    {
        $clock = new SystemClock(new MockClock('2024-01-01T10:00:00.000000Z'));

        $this->assertContains(
            $clock->now()->getTimezone()->getName(),
            ClockAssertion::UTC_VARIANTS,
        );
    }

    #[Test]
    public function returns_microsecond_precision(): void
    {
        $clock = new SystemClock(new MockClock('2024-01-01T10:00:00.123456Z'));

        $this->assertSame(6, strlen($clock->now()->format('u')));
    }

    #[Test]
    public function accepts_zero_microseconds(): void
    {
        $clock = new SystemClock(new MockClock('2024-01-01T10:00:00.000000Z'));

        $this->assertSame('000000', $clock->now()->format('u'));
    }

    #[Test]
    public function accepts_an_inner_clock_already_yielding_a_point_in_time(): void
    {
        // an inner PointInTime must be normalized, not tripped up by PointInTime's setTimezone guard
        $clock = new SystemClock(FrozenClock::at('2024-01-15T10:00:00.000000Z'));

        $this->assertSame('2024-01-15T10:00:00.000000+00:00', $clock->now()->toString());
    }

    #[Test]
    public function normalizes_non_utc_inner_clock(): void
    {
        // SystemClock normalizes to UTC regardless of inner clock timezone
        $inner = new MockClock(new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')));
        $clock = new SystemClock($inner);

        $this->assertContains(
            $clock->now()->getTimezone()->getName(),
            ClockAssertion::UTC_VARIANTS,
        );
    }

    #[Test]
    public function normalizes_paris_timezone_to_utc(): void
    {
        // Europe/Paris is UTC+1 in winter or UTC+2 in summer
        $paris = new DateTimeImmutable('2024-01-15T10:00:00.000000', new DateTimeZone('Europe/Paris'));
        $clock = new SystemClock(new MockClock($paris));

        $result = $clock->now();

        // UTC+1 in January: 10:00 Paris = 09:00 UTC
        $this->assertSame('09:00:00', $result->format('H:i:s'));
        $this->assertContains($result->getTimezone()->getName(), ClockAssertion::UTC_VARIANTS);
    }

    #[Test]
    public function formats_with_point_in_time_format(): void
    {
        $clock = new SystemClock(new MockClock('2024-01-01T10:00:00.123456Z'));

        $this->assertMatchesRegularExpression(
            ClockAssertion::RFC3339_REGEX,
            $clock->now()->format(PointInTime::FORMAT),
        );
    }

    #[Test]
    #[Group('slow')]
    public function now_is_always_fresh(): void
    {
        // the assertion compares INSTANTS, never object identities: two fresh objects are not
        // the same whatever the clock does, and an identity check here could not fail
        $clock = new SystemClock(new SymfonyClock);

        $first = $clock->now();
        usleep(1000);
        $second = $clock->now();

        $this->assertTrue($second->isAfter($first), 'a later read must carry a later instant');
    }
}
