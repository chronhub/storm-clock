<?php

declare(strict_types=1);

namespace Storm\Clock\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Clock\Duration;

final class DurationTest extends TestCase
{
    #[Test]
    #[DataProvider('valid')]
    public function parses_a_compact_duration_to_seconds(string $input, int $seconds): void
    {
        $this->assertSame($seconds, Duration::fromString($input)?->seconds);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function valid(): iterable
    {
        yield 'single day' => ['1d', 86400];
        yield 'single hour' => ['1h', 3600];
        yield 'single minute' => ['1m', 60];
        yield 'days' => ['30d', 2592000];
        yield 'hours' => ['48h', 172800];
        yield 'minutes' => ['90m', 5400];
        yield 'uppercase D' => ['7D', 604800];
        yield 'uppercase H' => ['2H', 7200];
        yield 'uppercase M' => ['15M', 900];
        yield 'inner space' => ['7 d', 604800];
        yield 'leading and trailing spaces' => ['  5m  ', 300];
        yield 'leading zero on a non-zero amount' => ['07d', 604800];
    }

    #[Test]
    #[DataProvider('malformed')]
    public function returns_null_for_a_malformed_duration(string $input): void
    {
        $this->assertNull(Duration::fromString($input));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformed(): iterable
    {
        yield 'empty' => [''];
        yield 'no unit' => ['30'];
        yield 'unknown unit' => ['30y'];
        yield 'not a number' => ['abc'];
        yield 'negative' => ['-5d'];
        yield 'trailing garbage' => ['5m_extra'];
        yield 'leading garbage' => ['prefix_5m'];
    }

    // -------------------------------------------------------------------------
    // Guards: zero and overflow
    // -------------------------------------------------------------------------

    #[Test]
    #[Group('adversarial')]
    #[DataProvider('zero_amounts')]
    public function rejects_a_zero_amount(string $input): void
    {
        // a zero retention would turn --before into a prune-all
        $this->assertNull(Duration::fromString($input));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function zero_amounts(): iterable
    {
        yield 'zero days' => ['0d'];
        yield 'zero hours' => ['0h'];
        yield 'zero minutes' => ['0m'];
        yield 'padded zeros' => ['00d'];
    }

    #[Test]
    #[Group('adversarial')]
    #[DataProvider('overflowing_amounts')]
    public function rejects_an_amount_whose_seconds_overflow(string $input): void
    {
        // the int cast of an over-long digit string saturates at PHP_INT_MAX; it must not
        // silently become a huge-but-wrong retention, nor escape as a TypeError
        $this->assertNull(Duration::fromString($input));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function overflowing_amounts(): iterable
    {
        yield 'hundred digit string' => [str_repeat('9', 100).'d'];
        yield 'int max as minutes' => [PHP_INT_MAX.'m'];
        yield 'days just past the cap' => [(intdiv(PHP_INT_MAX, 86400) + 1).'d'];
    }

    #[Test]
    public function accepts_the_largest_representable_amount(): void
    {
        $cap = intdiv(PHP_INT_MAX, 86400);

        $this->assertSame($cap * 86400, Duration::fromString($cap.'d')?->seconds);
    }
}
