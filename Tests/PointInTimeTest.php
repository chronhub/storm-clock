<?php

declare(strict_types=1);

namespace Storm\Clock\Tests;

use DateInterval;
use DateMalformedStringException;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Storm\Clock\ClockAssertion;
use Storm\Clock\Exception\ClockException;
use Storm\Clock\Exception\InvalidDateTimeException;
use Storm\Clock\PointInTime;

final class PointInTimeTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Construction: valid
    // -------------------------------------------------------------------------

    #[Test]
    public function creates_from_now(): void
    {
        $point = new PointInTime('now');

        $this->assertSame(PointInTime::class, $point::class);
        $this->assertSame(6, strlen($point->format('u')));
    }

    #[Test]
    public function from_refuses_the_relative_now_keyword(): void
    {
        // from()'s contract is "the string IS canonical"; the current instant comes from the clock,
        // or from the constructor in explicit cases
        $this->expectException(InvalidDateTimeException::class);

        PointInTime::from('now');
    }

    #[Test]
    public function creates_with_default_now(): void
    {
        $point = new PointInTime;

        $this->assertSame(PointInTime::class, $point::class);
    }

    #[Test]
    public function creates_from_a_datetime_normalizing_to_utc(): void
    {
        $point = PointInTime::fromDateTime(new DateTimeImmutable('2024-01-01T12:00:00.123456+02:00'));

        $this->assertSame('2024-01-01T10:00:00.123456+00:00', $point->toString());
    }

    #[Test]
    #[DataProvider('valid_datetimes')]
    public function creates_from_valid_string(string $datetime): void
    {
        $point = PointInTime::from($datetime);

        $this->assertSame(PointInTime::class, $point::class);
    }

    #[Test]
    public function timezone_is_always_utc_variant(): void
    {
        $point = PointInTime::from('2024-01-01T10:00:00.000000Z');

        $this->assertContains($point->getTimezone()->getName(), ClockAssertion::UTC_VARIANTS);
    }

    #[Test]
    public function accepts_zero_microseconds(): void
    {
        $point = PointInTime::from('2024-01-01T10:00:00.000000Z');

        $this->assertSame('000000', $point->format('u'));
    }

    #[Test]
    public function accepts_non_zero_microseconds(): void
    {
        $point = PointInTime::from('2024-01-01T10:00:00.123456Z');

        $this->assertSame('123456', $point->format('u'));
    }

    // -------------------------------------------------------------------------
    // Construction: invalid
    // -------------------------------------------------------------------------

    #[Test]
    #[DataProvider('invalid_datetimes')]
    public function throws_for_invalid_format(string $datetime): void
    {
        $this->expectException(InvalidDateTimeException::class);

        PointInTime::from($datetime);
    }

    #[Test]
    public function preserves_the_native_cause_when_wrapping_a_parse_failure(): void
    {
        try {
            PointInTime::from('totally not a date');
        } catch (InvalidDateTimeException $e) {
            $this->assertInstanceOf(DateMalformedStringException::class, $e->getPrevious());

            return;
        }

        $this->fail('Expected InvalidDateTimeException');
    }

    #[Test]
    public function the_constructor_wraps_a_parse_failure_with_its_cause(): void
    {
        // The same driver-to-domain translation as from(), but a distinct public entry with its own try/catch.
        try {
            new PointInTime('totally not a date');
        } catch (InvalidDateTimeException $e) {
            $this->assertInstanceOf(DateMalformedStringException::class, $e->getPrevious());

            return;
        }

        $this->fail('Expected InvalidDateTimeException');
    }

    // -------------------------------------------------------------------------
    // fromStorage
    // -------------------------------------------------------------------------

    #[Test]
    #[DataProvider('storage_shapes')]
    public function hydrates_a_stored_timestamp_to_the_canonical_form(string $raw, string $expected): void
    {
        $this->assertSame($expected, PointInTime::fromStorage($raw)->toString());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function storage_shapes(): iterable
    {
        yield 'PG short offset, full micros' => ['2026-06-03 12:21:57.202266+00', '2026-06-03T12:21:57.202266+00:00'];
        yield 'PG trims trailing fractional zeros' => ['2026-06-03 12:21:57.2+00', '2026-06-03T12:21:57.200000+00:00'];
        yield 'PG trims the whole fraction' => ['2026-06-03 12:21:57+00', '2026-06-03T12:21:57.000000+00:00'];
        yield 'canonical Z passes through' => ['2024-01-15T10:00:00.000000Z', '2024-01-15T10:00:00.000000+00:00'];
        yield 'full UTC offset' => ['2024-01-15 10:00:00.000000+00:00', '2024-01-15T10:00:00.000000+00:00'];
        yield 'non-UTC offset preserves the instant' => ['2024-01-15 10:00:00.000000+02:00', '2024-01-15T08:00:00.000000+00:00'];
        yield 'short non-UTC offset' => ['2024-01-15 10:00:00+02', '2024-01-15T08:00:00.000000+00:00'];
        yield 'compact offset' => ['2024-01-15 10:00:00+0200', '2024-01-15T08:00:00.000000+00:00'];
        yield 'negative offset' => ['2024-01-15 10:00:00-05:00', '2024-01-15T15:00:00.000000+00:00'];
        yield 'max positive offset +14:00' => ['2024-01-15 10:00:00.000000+14:00', '2024-01-14T20:00:00.000000+00:00'];
        yield 'offset with 59 minutes' => ['2024-01-15 10:00:00.000000+05:59', '2024-01-15T04:01:00.000000+00:00'];
        yield 'negative offset with 59 minutes' => ['2024-01-15 10:00:00.000000-05:59', '2024-01-15T15:59:00.000000+00:00'];
    }

    #[Test]
    #[Group('adversarial')]
    #[DataProvider('rejected_storage_values')]
    public function from_storage_rejects_a_corrupt_persisted_value(string $raw): void
    {
        // a persisted timestamp outside the driver-emitted shapes, or naming a nonexistent
        // instant, is corruption; it must fail loudly, never become a plausible date
        $this->expectException(InvalidDateTimeException::class);

        PointInTime::fromStorage($raw);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejected_storage_values(): iterable
    {
        // shape violations: a healthy driver never emits these
        yield 'relative keyword' => ['yesterday'];
        yield 'unparseable garbage' => ['totally not a date'];
        yield 'missing offset (would depend on the process timezone)' => ['2024-01-15 10:00:00.000000'];
        yield 'date only' => ['2024-01-15'];
        yield 'seven-digit fraction' => ['2024-01-15 10:00:00.1234567+00'];
        // rollovers: shape-valid text naming a nonexistent instant
        yield 'Feb 30 rolls over' => ['2024-02-30 10:00:00.000000+00'];
        yield 'hour 24 rolls over' => ['2024-01-15 24:00:00+00'];
        yield 'second 60 rolls over' => ['2024-01-15 23:59:60+00'];
        yield 'zero month rolls back' => ['2024-00-15 10:00:00+00'];
        yield 'zero day rolls back' => ['2024-01-00 10:00:00+00'];
        // offset semantics: shape-legal captures naming no real-world offset
        yield 'negative zero offset asserts unknown-local, not UTC' => ['2024-01-15 10:00:00-00'];
        yield 'negative zero full form' => ['2024-01-15 10:00:00-00:00'];
        yield 'offset hour beyond any real zone' => ['2024-01-15 10:00:00+24:00'];
        yield 'offset beyond 14 hours' => ['2024-01-15 10:00:00+15:00'];
        yield 'negative offset beyond 14 hours' => ['2024-01-15 10:00:00-15:00'];
        yield 'offset minutes beyond 59' => ['2024-01-15 10:00:00+02:99'];
        yield 'offset trailing characters' => ['2024-01-15 10:00:00.000000+02:00abc'];
        yield 'offset leading characters' => ['2024-01-15 10:00:00.000000abc+02:00'];
    }

    #[Test]
    public function from_storage_names_the_expected_shape_when_rejecting(): void
    {
        try {
            PointInTime::fromStorage('totally not a date');
        } catch (InvalidDateTimeException $e) {
            $this->assertStringContainsString('mandatory offset', $e->getMessage());

            return;
        }

        $this->fail('Expected InvalidDateTimeException');
    }

    // -------------------------------------------------------------------------
    // Inherited surface: invariant defense
    // -------------------------------------------------------------------------

    #[Test]
    #[Group('adversarial')]
    public function set_timezone_cannot_leave_utc(): void
    {
        $point = PointInTime::from('2024-01-15T10:00:00.000000Z');

        $this->expectException(ClockException::class);
        $this->expectExceptionMessageIsOrContains('always UTC');

        $point->setTimezone(new DateTimeZone('Europe/Paris'));
    }

    #[Test]
    public function create_from_format_renormalizes_to_utc(): void
    {
        // the native factory would return a Paris-timezone PointInTime without ever
        // passing through the constructor
        $point = PointInTime::createFromFormat('Y-m-d H:i:s', '2024-01-15 10:00:00', new DateTimeZone('Europe/Paris'));

        $this->assertNotFalse($point);
        $this->assertContains($point->getTimezone()->getName(), ClockAssertion::UTC_VARIANTS);
        $this->assertSame('2024-01-15T09:00:00.000000+00:00', $point->toString());
    }

    #[Test]
    public function create_from_format_keeps_the_native_false_on_parse_failure(): void
    {
        $this->assertFalse(PointInTime::createFromFormat('Y-m-d', 'not a date'));
    }

    #[Test]
    #[Group('adversarial')]
    public function create_from_format_refuses_a_date_component_rollover(): void
    {
        $this->assertFalse(PointInTime::createFromFormat('!Y-m-d', '2026-02-30', new DateTimeZone('UTC')));
    }

    #[Test]
    #[Group('adversarial')]
    public function create_from_format_refuses_a_time_component_rollover(): void
    {
        $this->assertFalse(PointInTime::createFromFormat('!Y-m-d H:i:s', '2026-02-28 24:00:00', new DateTimeZone('UTC')));
    }

    #[Test]
    public function create_from_format_softens_the_native_value_error_to_false(): void
    {
        // a NUL byte in the datetime is the input the native factory reports as a ValueError
        // ("must not contain any null bytes") instead of a soft false; the boundary promises
        // no native exception type escapes, so it lands as false like any other unparseable input
        $this->assertFalse(PointInTime::createFromFormat('Y-m-d', "2024-01-15\0"));
    }

    #[Test]
    public function create_from_interface_renormalizes_to_utc(): void
    {
        $point = PointInTime::createFromInterface(
            new DateTimeImmutable('2024-01-15 10:00:00', new DateTimeZone('Europe/Paris')),
        );

        $this->assertSame('2024-01-15T09:00:00.000000+00:00', $point->toString());
    }

    #[Test]
    public function create_from_mutable_renormalizes_to_utc(): void
    {
        $point = PointInTime::createFromMutable(
            new DateTime('2024-01-15 10:00:00', new DateTimeZone('Europe/Paris')),
        );

        $this->assertSame('2024-01-15T09:00:00.000000+00:00', $point->toString());
    }

    #[Test]
    #[Group('adversarial')]
    public function modify_wraps_the_native_failure(): void
    {
        // no native exception may leave the type; the wrapped cause stays reachable
        $point = PointInTime::from('2024-01-15T10:00:00.000000Z');

        try {
            $point->modify('this is garbage');
        } catch (InvalidDateTimeException $e) {
            $this->assertInstanceOf(DateMalformedStringException::class, $e->getPrevious());

            return;
        }

        $this->fail('Expected InvalidDateTimeException');
    }

    #[Test]
    #[Group('adversarial')]
    public function modify_refuses_an_absolute_date_component_rollover(): void
    {
        $point = PointInTime::from('2024-01-15T10:00:00.000000Z');

        $this->expectException(InvalidDateTimeException::class);

        $point->modify('2026-02-30');
    }

    #[Test]
    #[Group('adversarial')]
    public function modify_refuses_an_absolute_time_component_rollover(): void
    {
        $point = PointInTime::from('2024-01-15T10:00:00.000000Z');

        $this->expectException(InvalidDateTimeException::class);

        $point->modify('24:00:00');
    }

    #[Test]
    public function modify_keeps_the_native_relative_overflow(): void
    {
        // the guard must not reach the relative forms: a short target month is documented native
        // arithmetic, not a corrupt modifier
        $point = PointInTime::from('2026-01-31T10:00:00.000000Z');

        $this->assertSame('2026-03-03T10:00:00.000000+00:00', $point->modify('+1 month')->toString());
    }

    #[Test]
    #[DataProvider('utc_preserving_operations')]
    public function utc_preserving_native_methods_keep_the_invariant(callable $op, string $expected): void
    {
        $result = $op(PointInTime::from('2024-01-15T10:00:00.000000Z'));

        $this->assertSame(PointInTime::class, $result::class);
        $this->assertContains($result->getTimezone()->getName(), ClockAssertion::UTC_VARIANTS);
        $this->assertSame($expected, $result->toString());
    }

    /**
     * @return iterable<string, array{callable(PointInTime): PointInTime, string}>
     */
    public static function utc_preserving_operations(): iterable
    {
        yield 'modify relative' => [fn (PointInTime $p) => $p->modify('+1 day'), '2024-01-16T10:00:00.000000+00:00'];
        yield 'add interval' => [fn (PointInTime $p) => $p->add(new DateInterval('P1D')), '2024-01-16T10:00:00.000000+00:00'];
        yield 'sub interval' => [fn (PointInTime $p) => $p->sub(new DateInterval('PT1H')), '2024-01-15T09:00:00.000000+00:00'];
        yield 'setDate' => [fn (PointInTime $p) => $p->setDate(2025, 2, 3), '2025-02-03T10:00:00.000000+00:00'];
        yield 'setISODate' => [fn (PointInTime $p) => $p->setISODate(2025, 6, 2), '2025-02-04T10:00:00.000000+00:00'];
        yield 'setTime' => [fn (PointInTime $p) => $p->setTime(1, 2, 3), '2024-01-15T01:02:03.000000+00:00'];
        yield 'setTimestamp' => [fn (PointInTime $p) => $p->setTimestamp(1705312800), '2024-01-15T10:00:00.000000+00:00'];
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_year_beyond_9999_at_normalization(): void
    {
        // a five-digit year has no canonical shape; the invariant caps the representable range
        $tooFar = new DateTimeImmutable('9999-12-31 23:59:59', new DateTimeZone('UTC'))->modify('+1 day');

        $this->expectException(InvalidDateTimeException::class);

        PointInTime::fromDateTime($tooFar);
    }

    // -------------------------------------------------------------------------
    // Comparison
    // -------------------------------------------------------------------------

    #[Test]
    public function is_before_another_point(): void
    {
        $earlier = PointInTime::from('2024-01-01T10:00:00.000000Z');
        $later = PointInTime::from('2024-01-01T11:00:00.000000Z');

        $this->assertTrue($earlier->isBefore($later));
        $this->assertFalse($later->isBefore($earlier));
    }

    #[Test]
    public function is_after_another_point(): void
    {
        $earlier = PointInTime::from('2024-01-01T10:00:00.000000Z');
        $later = PointInTime::from('2024-01-01T11:00:00.000000Z');

        $this->assertTrue($later->isAfter($earlier));
        $this->assertFalse($earlier->isAfter($later));
    }

    #[Test]
    public function equals_same_point(): void
    {
        $a = PointInTime::from('2024-01-01T10:00:00.000000Z');
        $b = PointInTime::from('2024-01-01T10:00:00.000000Z');

        $this->assertTrue($a->equals($b));
    }

    #[Test]
    public function equals_across_the_z_and_offset_spellings_of_one_instant(): void
    {
        $z = PointInTime::from('2024-01-15T10:00:00.000000Z');
        $offset = PointInTime::from('2024-01-15T10:00:00.000000+00:00');

        $this->assertTrue($z->equals($offset));
    }

    #[Test]
    public function not_equals_different_points(): void
    {
        $a = PointInTime::from('2024-01-01T10:00:00.000000Z');
        $b = PointInTime::from('2024-01-01T11:00:00.000000Z');

        $this->assertFalse($a->equals($b));
    }

    // -------------------------------------------------------------------------
    // Mutation
    // -------------------------------------------------------------------------

    #[Test]
    public function add_seconds_returns_new_instance(): void
    {
        $point = PointInTime::from('2024-01-01T10:00:00.000000Z');
        $result = $point->addSeconds(30);

        $this->assertNotSame($point, $result);
        $this->assertSame('10:00:30', $result->format('H:i:s'));
    }

    #[Test]
    public function add_minutes_returns_new_instance(): void
    {
        $point = PointInTime::from('2024-01-01T10:00:00.000000Z');
        $result = $point->addMinutes(15);

        $this->assertNotSame($point, $result);
        $this->assertSame('10:15:00', $result->format('H:i:s'));
    }

    #[Test]
    public function add_hours_returns_new_instance(): void
    {
        $point = PointInTime::from('2024-01-01T10:00:00.000000Z');
        $result = $point->addHours(2);

        $this->assertNotSame($point, $result);
        $this->assertSame('12:00:00', $result->format('H:i:s'));
    }

    #[Test]
    public function add_days_returns_new_instance(): void
    {
        $point = PointInTime::from('2024-01-01T10:00:00.000000Z');
        $result = $point->addDays(3);

        $this->assertNotSame($point, $result);
        $this->assertSame('2024-01-04', $result->format('Y-m-d'));
    }

    #[Test]
    public function sub_seconds_returns_new_instance(): void
    {
        $point = PointInTime::from('2024-01-01T10:00:30.000000Z');
        $result = $point->subSeconds(30);

        $this->assertNotSame($point, $result);
        $this->assertSame('10:00:00', $result->format('H:i:s'));
    }

    #[Test]
    public function sub_minutes_returns_new_instance(): void
    {
        $point = PointInTime::from('2024-01-01T10:15:00.000000Z');
        $result = $point->subMinutes(15);

        $this->assertNotSame($point, $result);
        $this->assertSame('10:00:00', $result->format('H:i:s'));
    }

    #[Test]
    public function sub_hours_returns_new_instance(): void
    {
        $point = PointInTime::from('2024-01-01T12:00:00.000000Z');
        $result = $point->subHours(2);

        $this->assertNotSame($point, $result);
        $this->assertSame('10:00:00', $result->format('H:i:s'));
    }

    #[Test]
    public function sub_days_returns_new_instance(): void
    {
        $point = PointInTime::from('2024-01-04T10:00:00.000000Z');
        $result = $point->subDays(3);

        $this->assertNotSame($point, $result);
        $this->assertSame('2024-01-01', $result->format('Y-m-d'));
    }

    #[Test]
    #[Group('adversarial')]
    #[DataProvider('non_positive_arithmetic')]
    public function arithmetic_rejects_a_non_positive_amount(callable $op): void
    {
        // zero would silently no-op; a negative amount would silently invert the direction
        $point = PointInTime::from('2024-01-15T10:00:00.000000Z');

        $this->expectException(InvalidDateTimeException::class);

        $op($point);
    }

    /**
     * @return iterable<string, array{callable(PointInTime): PointInTime}>
     */
    public static function non_positive_arithmetic(): iterable
    {
        yield 'addSeconds zero' => [fn (PointInTime $p) => $p->addSeconds(0)]; // @phpstan-ignore argument.type (hostile on purpose: the guard under test)
        yield 'addSeconds negative' => [fn (PointInTime $p) => $p->addSeconds(-5)]; // @phpstan-ignore argument.type (hostile on purpose: the guard under test)
        yield 'addMinutes zero' => [fn (PointInTime $p) => $p->addMinutes(0)]; // @phpstan-ignore argument.type (hostile on purpose: the guard under test)
        yield 'addHours negative' => [fn (PointInTime $p) => $p->addHours(-1)]; // @phpstan-ignore argument.type (hostile on purpose: the guard under test)
        yield 'addDays zero' => [fn (PointInTime $p) => $p->addDays(0)]; // @phpstan-ignore argument.type (hostile on purpose: the guard under test)
        yield 'subSeconds negative' => [fn (PointInTime $p) => $p->subSeconds(-5)]; // @phpstan-ignore argument.type (hostile on purpose: the guard under test)
        yield 'subMinutes zero' => [fn (PointInTime $p) => $p->subMinutes(0)]; // @phpstan-ignore argument.type (hostile on purpose: the guard under test)
        yield 'subHours negative' => [fn (PointInTime $p) => $p->subHours(-2)]; // @phpstan-ignore argument.type (hostile on purpose: the guard under test)
        yield 'subDays zero' => [fn (PointInTime $p) => $p->subDays(0)]; // @phpstan-ignore argument.type (hostile on purpose: the guard under test)
    }

    #[Test]
    public function original_is_not_mutated(): void
    {
        $point = PointInTime::from('2024-01-01T10:00:00.000000Z');
        $original = $point->format('H:i:s');

        $point->addSeconds(30);
        $point->addMinutes(15);
        $point->addHours(2);

        $this->assertSame($original, $point->format('H:i:s'));
    }

    // -------------------------------------------------------------------------
    // Output
    // -------------------------------------------------------------------------

    #[Test]
    public function to_string_uses_rfc3339_format(): void
    {
        $point = PointInTime::from('2024-01-01T10:00:00.123456Z');

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}[+-]\d{2}:\d{2}$/',
            (string) $point,
        );
    }

    #[Test]
    public function to_string_equals_format(): void
    {
        $point = PointInTime::from('2024-01-01T10:00:00.123456Z');

        $this->assertSame($point->format(PointInTime::FORMAT), $point->toString());
        $this->assertSame($point->toString(), (string) $point);
    }

    // -------------------------------------------------------------------------
    // The storable guard on every computing exit
    // -------------------------------------------------------------------------

    #[Test]
    public function arithmetic_that_leaves_the_storable_year_range_throws_instead_of_minting_an_unreadable_instant(): void
    {
        // unguarded, addSeconds(3×10¹¹) mints a really-typed PointInTime in year 11533 that
        // neither from() nor fromStorage() accepts; a saga timer written with it would persist,
        // then refuse to hydrate; the lie must die at computation, loudly, never clamp
        $point = PointInTime::from('2026-07-30T10:00:00.000000+00:00');

        $this->expectException(InvalidDateTimeException::class);
        $this->expectExceptionMessageIsOrContains('storable year range');

        $point->addSeconds(300_000_000_000);
    }

    #[Test]
    public function the_native_add_exit_carries_the_same_storable_guard(): void
    {
        // the helpers route through modify(); the inherited add()/sub() do not; the guard must
        // hold on every arithmetic exit or the widest one stays the escape
        $point = PointInTime::from('2026-07-30T10:00:00.000000+00:00');

        $this->expectException(InvalidDateTimeException::class);
        $this->expectExceptionMessageIsOrContains('storable year range');

        $point->add(new DateInterval('P9000Y'));
    }

    #[Test]
    public function the_native_sub_exit_refuses_a_computed_year_below_one(): void
    {
        $point = PointInTime::from('2026-07-30T10:00:00.000000+00:00');

        $this->expectException(InvalidDateTimeException::class);
        $this->expectExceptionMessageIsOrContains('storable year range');

        $point->sub(new DateInterval('P2300Y'));
    }

    #[Test]
    public function in_range_arithmetic_still_flows_on_every_exit(): void
    {
        $point = PointInTime::from('2026-07-30T10:00:00.000000+00:00');

        $this->assertSame('2026-07-30T10:00:01.000000+00:00', $point->addSeconds(1)->toString());
        $this->assertSame('2027-07-30T10:00:00.000000+00:00', $point->add(new DateInterval('P1Y'))->toString());
        $this->assertSame('2025-07-30T10:00:00.000000+00:00', $point->sub(new DateInterval('P1Y'))->toString());
    }

    #[Test]
    public function create_from_timestamp_renormalizes_like_its_siblings(): void
    {
        $point = PointInTime::createFromTimestamp(1_753_869_600);

        $this->assertSame(PointInTime::class, $point::class);
        $this->assertSame('2025-07-30T10:00:00.000000+00:00', $point->toString());
    }

    #[Test]
    public function a_timestamp_beyond_the_storable_range_refuses(): void
    {
        $this->expectException(InvalidDateTimeException::class);

        PointInTime::createFromTimestamp(300_000_000_000); // ~year 11 476
    }

    #[Test]
    #[Group('adversarial')]
    public function a_non_finite_timestamp_stays_inside_the_clock_exception_contract(): void
    {
        // the native timestamp factory throws DateRangeError for a non-finite value; the wrapper
        // converts it, keeping the promise that no native failure type leaves the clock
        $this->expectException(InvalidDateTimeException::class);

        PointInTime::createFromTimestamp(INF);
    }

    #[Test]
    public function set_date_carries_the_storable_guard(): void
    {
        $point = PointInTime::from('2026-07-30T10:00:00.000000+00:00');

        $this->expectException(InvalidDateTimeException::class);
        $this->expectExceptionMessageIsOrContains('storable year range');

        $point->setDate(10000, 1, 1);
    }

    #[Test]
    public function set_iso_date_carries_the_storable_guard(): void
    {
        $point = PointInTime::from('2026-07-30T10:00:00.000000+00:00');

        $this->expectException(InvalidDateTimeException::class);
        $this->expectExceptionMessageIsOrContains('storable year range');

        $point->setISODate(10000, 1, 1);
    }

    #[Test]
    public function set_iso_date_refuses_a_computed_year_below_one(): void
    {
        $point = PointInTime::from('2026-07-30T10:00:00.000000+00:00');

        $this->expectException(InvalidDateTimeException::class);
        $this->expectExceptionMessageIsOrContains('storable year range');

        $point->setISODate(0, 1, 1);
    }

    #[Test]
    public function set_timestamp_carries_the_storable_guard(): void
    {
        // 300 billion seconds mints year 11476, really typed as PointInTime and refused by both
        // from() and fromStorage(): the exact lie unstorable() exists to kill at the computation
        $point = PointInTime::from('2026-07-30T10:00:00.000000+00:00');

        $this->expectException(InvalidDateTimeException::class);
        $this->expectExceptionMessageIsOrContains('storable year range');

        $point->setTimestamp(300_000_000_000);
    }

    #[Test]
    public function set_time_carries_the_storable_guard(): void
    {
        // the one reachable edge its clause names: an out-of-range hour rolls the last storable
        // day into year 10000
        $point = PointInTime::from('9999-12-31T10:00:00.000000+00:00');

        $this->expectException(InvalidDateTimeException::class);
        $this->expectExceptionMessageIsOrContains('storable year range');

        $point->setTime(48, 0);
    }

    #[Test]
    #[Group('adversarial')]
    public function every_native_mutating_exit_is_redeclared_on_point_in_time(): void
    {
        // the invariant is only as complete as the override list, and PHP grows this surface across
        // minors: any native method able to mint a new instant must be redeclared here, so a new PHP
        // release fails this test instead of silently reopening the hole. The serialization family
        // stays native: it is the documented trust boundary no override can defend.
        $nativeOnly = ['__set_state', '__unserialize', '__wakeup'];

        foreach (new ReflectionClass(DateTimeImmutable::class)->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (in_array($method->getName(), $nativeOnly, true)) {
                continue;
            }

            $returns = (string) $method->getReturnType();
            if (! str_contains($returns, 'DateTimeImmutable') && ! str_contains($returns, 'static')) {
                continue;
            }

            $declaring = new ReflectionMethod(PointInTime::class, $method->getName())->getDeclaringClass()->getName();

            $this->assertSame(PointInTime::class, $declaring, sprintf('%s() can mint an instant and is not redeclared on PointInTime.', $method->getName()));
        }
    }

    #[Test]
    public function set_microsecond_wraps_the_native_range_error(): void
    {
        $point = PointInTime::from('2026-07-30T10:00:00.000000+00:00');

        $this->expectException(InvalidDateTimeException::class);

        $point->setMicrosecond(-1);
    }

    #[Test]
    public function set_iso_date_defaults_to_monday(): void
    {
        $point = PointInTime::from('2024-01-15T10:00:00.000000Z');
        $updated = $point->setISODate(2024, 10);

        $this->assertSame('1', $updated->format('N'));
        $this->assertSame('2024-03-04T10:00:00.000000+00:00', $updated->toString());
    }

    #[Test]
    public function set_time_defaults_to_zero_seconds_and_microseconds(): void
    {
        $point = PointInTime::from('2024-01-15T10:15:30.123456Z');
        $updated = $point->setTime(14, 45);

        $this->assertSame('2024-01-15T14:45:00.000000+00:00', $updated->toString());
    }

    #[Test]
    public function supports_year_boundaries_from_one_to_four_nines(): void
    {
        $point = PointInTime::from('2024-01-15T10:00:00.000000Z');

        $yearOne = $point->setDate(1, 1, 1);
        $this->assertSame('0001-01-01T10:00:00.000000+00:00', $yearOne->toString());

        $yearMax = $point->setDate(9999, 12, 31);
        $this->assertSame('9999-12-31T10:00:00.000000+00:00', $yearMax->toString());
    }

    #[Test]
    public function set_date_rejects_year_zero(): void
    {
        $point = PointInTime::from('2024-01-15T10:00:00.000000Z');

        $this->expectException(InvalidDateTimeException::class);
        $point->setDate(0, 1, 1);
    }

    #[Test]
    public function set_date_rejects_year_ten_thousand(): void
    {
        $point = PointInTime::from('2024-01-15T10:00:00.000000Z');

        $this->expectException(InvalidDateTimeException::class);
        $point->setDate(10000, 1, 1);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function valid_datetimes(): array
    {
        return [
            'Z + zero micros' => ['2024-01-01T10:00:00.000000Z'],
            'Z + non-zero micros' => ['2024-01-01T10:00:00.123456Z'],
            'Z + max micros' => ['2024-01-01T10:00:00.999999Z'],
            '+00:00 offset' => ['2024-01-01T10:00:00.000000+00:00'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalid_datetimes(): array
    {
        return [
            'no microseconds' => ['2024-01-01T10:00:00Z'],
            '+00 offset' => ['2024-01-01T10:00:00.000000+00'],
            '3 digits milliseconds' => ['2024-01-01T10:00:00.123Z'],
            '5 digits' => ['2024-01-01T10:00:00.12345Z'],
            'missing UTC' => ['2024-01-01T10:00:00.000000'],
            'non-UTC timezone' => ['2024-01-01T10:00:00.000000+02:00'],
            'unknown local offset (-00:00)' => ['2024-01-01T10:00:00.000000-00:00'],
            'space separator' => ['2024-01-01 10:00:00.000000Z'],
            'date only' => ['2024-01-01'],
            'natural language' => ['yesterday'],
            'unparseable garbage' => ['totally not a date'],
            // out-of-range: shape-valid, but the lenient parser would roll these over to a different instant
            'out-of-range day (Feb 30)' => ['2024-02-30T10:00:00.000000Z'],
            'out-of-range hour (24)' => ['2024-01-01T24:00:00.000000Z'],
            'out-of-range second (:60)' => ['2024-01-01T23:59:60.000000Z'],
            'zero day' => ['2024-01-00T10:00:00.000000Z'],
            'zero month' => ['2024-00-15T10:00:00.000000Z'],
        ];
    }
}
