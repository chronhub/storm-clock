<?php

declare(strict_types=1);

namespace Storm\Clock\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Storm\Clock\Exception\ClockException;
use Storm\Clock\Exception\InvalidDateTimeException;
use Storm\Contracts\Clock\ClockExceptionContract;

/**
 * The exception seam of the Clock port: the contract's throws clause names the Contracts-owned
 * interface, the implementation hierarchy carries it; both catch altitudes must hold.
 */
final class ClockExceptionTest extends TestCase
{
    #[Test]
    public function a_concrete_clock_failure_is_catchable_by_the_contracted_domain_type(): void
    {
        $failure = InvalidDateTimeException::outOfRange('2024-02-30T10:00:00.000000+00:00');

        $this->assertInstanceOf(ClockExceptionContract::class, $failure);
    }

    #[Test]
    public function a_concrete_clock_failure_keeps_its_spl_base(): void
    {
        $failure = InvalidDateTimeException::outOfRange('2024-02-30T10:00:00.000000+00:00');

        $this->assertInstanceOf(RuntimeException::class, $failure);
    }

    #[Test]
    public function cannot_leave_utc_formats_full_guidance_message(): void
    {
        $failure = ClockException::cannotLeaveUtc();

        $this->assertSame(
            'PointInTime is always UTC; a timezone change cannot produce one. For a local-time view, use DateTimeImmutable::createFromInterface($point)->setTimezone(...).',
            $failure->getMessage()
        );
    }

    #[Test]
    public function invalid_format_exception_formats_expected_and_got(): void
    {
        $previous = new RuntimeException('cause');
        $failure = InvalidDateTimeException::invalidFormat('invalid_val', 'expected_fmt', $previous);

        $this->assertSame("Invalid datetime format. Expected 'expected_fmt', got 'invalid_val'.", $failure->getMessage());
        $this->assertSame($previous, $failure->getPrevious());
    }

    #[Test]
    public function unparseable_exception_formats_message(): void
    {
        $previous = new RuntimeException('cause');
        $failure = InvalidDateTimeException::unparseable('bad_val', $previous);

        $this->assertSame("Malformed datetime, cannot be parsed: 'bad_val'.", $failure->getMessage());
        $this->assertSame($previous, $failure->getPrevious());
    }

    #[Test]
    public function unstorable_exception_formats_message(): void
    {
        $failure = InvalidDateTimeException::unstorable('10000-01-01');

        $this->assertSame(
            "Computed datetime '10000-01-01' left the storable year range 0001-9999 — it could never be read back by from()/fromStorage(); bound the arithmetic amount.",
            $failure->getMessage()
        );
    }

    #[Test]
    public function non_positive_amount_formats_message(): void
    {
        $failure = InvalidDateTimeException::nonPositiveAmount(0);

        $this->assertSame('Datetime arithmetic expects a positive amount of units, got 0.', $failure->getMessage());
    }
}
