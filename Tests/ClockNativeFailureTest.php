<?php

declare(strict_types=1);

namespace Storm\Clock\Tests;

use DateInterval;
use DateInvalidOperationException;
use PHPUnit\Framework\TestCase;
use Storm\Clock\Exception\InvalidDateTimeException;
use Storm\Clock\PointInTime;
use Storm\Contracts\Clock\ClockExceptionContract;
use Throwable;

final class ClockNativeFailureTest extends TestCase
{
    public function test_native_subtraction_refusal_stays_in_the_clock_exception_family(): void
    {
        $point = PointInTime::from('2026-09-05T12:00:00.123456+00:00');
        $interval = DateInterval::createFromDateString('next weekday');
        self::assertInstanceOf(DateInterval::class, $interval);
        self::assertSame('2026-09-07T12:00:00.123456+00:00', $point->add($interval)->toString());
        $failure = null;
        try {
            $point->sub($interval);
        } catch (Throwable $e) {
            $failure = $e;
        }
        self::assertInstanceOf(ClockExceptionContract::class, $failure);
        self::assertInstanceOf(InvalidDateTimeException::class, $failure);
        $native = $failure->getPrevious();
        self::assertInstanceOf(DateInvalidOperationException::class, $native);
        self::assertSame('Cannot subtract date interval: '.$native->getMessage(), $failure->getMessage());
        self::assertSame('2026-09-05T12:00:00.123456+00:00', $point->toString());
    }

    public function test_regular_subtraction_preserves_microseconds_and_leaves_the_original_unchanged(): void
    {
        $point = PointInTime::from('2026-09-05T12:00:00.123456+00:00');
        $subtracted = $point->sub(new DateInterval('P1D'));

        self::assertSame('2026-09-04T12:00:00.123456+00:00', $subtracted->toString());
        self::assertSame('2026-09-05T12:00:00.123456+00:00', $point->toString());
        self::assertNotSame($point, $subtracted);
    }
}
