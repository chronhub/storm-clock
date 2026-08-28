<?php

declare(strict_types=1);

namespace Storm\Clock\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Clock\ClockAssertion;
use Storm\Clock\Exception\InvalidDateTimeException;

final class ClockAssertionTest extends TestCase
{
    #[Test]
    public function rfc3339_regex_pins_the_canonical_utc_shape(): void
    {
        $this->assertSame(
            ClockAssertion::RFC3339_REGEX,
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}(Z|\+00:00)\z/'
        );
    }

    // -------------------------------------------------------------------------
    // UTC_VARIANTS
    // -------------------------------------------------------------------------

    #[Test]
    public function utc_variants_contains_expected_values(): void
    {
        $this->assertContains('UTC', ClockAssertion::UTC_VARIANTS);
        $this->assertContains('Z', ClockAssertion::UTC_VARIANTS);
        $this->assertContains('+00:00', ClockAssertion::UTC_VARIANTS);
    }

    #[Test]
    public function utc_variants_has_exactly_three_entries(): void
    {
        $this->assertCount(3, ClockAssertion::UTC_VARIANTS);
    }

    // -------------------------------------------------------------------------
    // assertFormat: valid
    // -------------------------------------------------------------------------

    #[Test]
    #[DataProvider('valid_formats')]
    public function does_not_throw_for_valid_format(string $datetime): void
    {
        $this->expectNotToPerformAssertions();
        ClockAssertion::assertFormat($datetime);
    }

    // -------------------------------------------------------------------------
    // assertFormat: invalid
    // -------------------------------------------------------------------------

    #[Test]
    #[DataProvider('invalid_formats')]
    public function throws_for_invalid_format(string $datetime): void
    {
        $this->expectException(InvalidDateTimeException::class);
        ClockAssertion::assertFormat($datetime);
    }

    // -------------------------------------------------------------------------
    // Data Providers
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function valid_formats(): array
    {
        return [
            'Z suffix + zero micros' => ['2024-01-01T10:00:00.000000Z'],
            'Z suffix + non-zero micros' => ['2024-01-01T10:00:00.123456Z'],
            'Z suffix + max micros' => ['2024-01-01T10:00:00.999999Z'],
            '+00:00 offset' => ['2024-01-01T10:00:00.000000+00:00'],
            'mixed microseconds' => ['2024-01-01T10:00:00.100200Z'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalid_formats(): array
    {
        return [
            '+00 offset' => ['2024-01-01T10:00:00.000000+00'],
            'no microseconds' => ['2024-01-01T10:00:00Z'],
            '1 digit' => ['2024-01-01T10:00:00.1Z'],
            '2 digits' => ['2024-01-01T10:00:00.12Z'],
            '3 digits milliseconds' => ['2024-01-01T10:00:00.123Z'],
            '4 digits' => ['2024-01-01T10:00:00.1234Z'],
            '5 digits' => ['2024-01-01T10:00:00.12345Z'],
            'missing UTC designator' => ['2024-01-01T10:00:00.000000'],
            'non-UTC offset' => ['2024-01-01T10:00:00.000000+02:00'],
            'unknown local offset (-00:00)' => ['2024-01-01T10:00:00.000000-00:00'],
            'space separator' => ['2024-01-01 10:00:00.000000Z'],
            'natural language' => ['yesterday'],
            'date only' => ['2024-01-01'],
            'empty string' => [''],
        ];
    }
}
