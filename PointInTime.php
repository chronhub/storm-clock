<?php

declare(strict_types=1);

namespace Storm\Clock;

use DateInterval;
use DateInvalidOperationException;
use DateMalformedStringException;
use DateRangeError;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Override;
use Storm\Clock\Exception\ClockException;
use Storm\Clock\Exception\InvalidDateTimeException;
use Stringable;
use ValueError;

/**
 * Immutable point in time, always UTC with microsecond precision.
 *
 * Accepts only RFC 3339 format with explicit UTC and microsecond precision:
 *   - `2024-01-01T10:00:00.000000Z`
 *   - `2024-01-01T10:00:00.123456+00:00`
 *   - `now`, normalized automatically
 *
 * Rejects:
 *   - Missing microseconds: `2024-01-01T10:00:00Z`
 *   - Non-UTC timezone: `2024-01-01T10:00:00.000000+02:00`
 *   - Invalid format: `2024-01-01 10:00:00`
 *
 * Always go through `SystemClock::now()` or `PointInTime::from()` when possible.
 * Direct instantiation is supported for explicit cases such as interval boundaries.
 *
 * The UTC invariant is defended against the inherited surface, whose routes do not pass through
 * the constructor: `setTimezone()` throws, the native `createFrom*` factories renormalize through
 * `fromDateTime()`, and `modify()` wraps the native failure.
 *
 * Every exit that COMPUTES an instant additionally guards the STORABLE form; a computed year outside
 * 0001-9999 throws instead of minting a value `from()`/`fromStorage()` could never read back:
 *
 * - `modify()`, which every `add*`/`sub*` helper routes through, and the native `add()`/`sub()`
 *
 * - The native setters `setDate`, `setISODate`, `setTime` and `setTimestamp`; `setMicrosecond`
 *   cannot move the year, and wraps the native `DateRangeError` instead, so no native failure
 *   type escapes
 *
 * - `createFromTimestamp`, which renormalizes like its `createFrom*` siblings
 *
 * The read-only natives such as `format`, `diff` and `getTimestamp` stay untouched. Honest limit:
 * PHP unserialization restores fields without any constructor, so a hostile serialized payload is a
 * trust boundary no override can defend.
 *
 * Inspired by EventSauce and Greg Young's PointInTime pattern.
 *
 * @phpstan-consistent-constructor
 */
final class PointInTime extends DateTimeImmutable implements Stringable
{
    /**
     * RFC 3339 with microseconds and UTC timezone.
     */
    public const string FORMAT = 'Y-m-d\TH:i:s.uP';

    /**
     * Storage timestamp shape: the text form PostgreSQL renders for a `timestamptz`, plus the
     * canonical RFC 3339 form. Space or `T` separator, an optional fraction of 1 to 6 digits, and a
     * mandatory offset in one of these forms:
     *
     * - UTC `Z`
     * - Short `+00`
     * - Compact `+0200`
     * - Hours and minutes `+02:00`
     * - Historical seconds `+00:09:21`
     *
     * PostgreSQL trims trailing fractional zeros, down to none. Relative keywords and offset-less
     * values, whose meaning would depend on the process timezone, do not match.
     */
    private const string STORAGE_REGEX = '/^(?<date>\d{4}-\d{2}-\d{2})[ T](?<time>\d{2}:\d{2}:\d{2})(?:\.(?<fraction>\d{1,6}))?(?<offset>Z|[+-]\d{2}(?::\d{2}(?::\d{2})?|\d{2})?)\z/';

    /**
     * @throws InvalidDateTimeException
     */
    public function __construct(string $datetime = 'now')
    {
        try {
            parent::__construct($datetime, new DateTimeZone('UTC'));
        } catch (DateMalformedStringException $e) {
            throw InvalidDateTimeException::unparseable($datetime, $e);
        }

        // 'now': normalize to guaranteed valid format
        $normalized = $datetime === 'now'
            ? $this->format(self::FORMAT)
            : $datetime;

        ClockAssertion::assertFormat($normalized);
        $this->storable($this);

        // The shape check accepts an out-of-range date such as Feb 30, hour 24:60, or day/month 00
        // that the lenient parser silently rolled over to a different instant. Reject it: a canonical
        // literal round-trips to itself, where Z is +00:00, while a rolled-over one reformats
        // differently, for example 2024-02-30 becomes 2024-03-01.
        if ($datetime !== 'now' && $this->format(self::FORMAT) !== str_replace('Z', '+00:00', $datetime)) {
            throw InvalidDateTimeException::outOfRange($datetime);
        }
    }

    /**
     * Create from a datetime string. Must be RFC 3339 with explicit UTC and microsecond precision;
     * the relative `now` is refused, since this factory's contract is "the string IS canonical".
     * The current instant comes from `SystemClock::now()`, or `new PointInTime()` in explicit cases.
     *
     * @throws InvalidDateTimeException
     */
    public static function from(string $datetime): self
    {
        if ($datetime === 'now') {
            throw InvalidDateTimeException::invalidFormat('now', self::FORMAT);
        }

        return new self($datetime);
    }

    /**
     * Create from any DateTimeInterface, normalizing to UTC and the canonical format. Useful for
     * DB-sourced timestamps, which a driver may hand back in the session timezone.
     *
     * @throws InvalidDateTimeException
     */
    public static function fromDateTime(DateTimeInterface $dateTime): self
    {
        return new self(
            DateTimeImmutable::createFromInterface($dateTime)
                ->setTimezone(new DateTimeZone('UTC'))
                ->format(self::FORMAT),
        );
    }

    /**
     * Create from a raw stored timestamp string that may be non-canonical, typically the event store's
     * `recorded_at`, which Postgres renders space-separated with a short offset such as
     * `2026-06-03 12:21:57.202266+00`. Only the shapes a driver actually emits are accepted, see
     * `STORAGE_REGEX`; a persisted value outside them is corruption and fails loudly rather than
     * being silently reinterpreted. A shape-valid value naming a nonexistent instant such as Feb 30
     * or hour 24, which the lenient parser would roll over, is rejected the same way. A non-UTC offset
     * is converted to UTC with the instant preserved, not shifted: `10:00+02:00` and `08:00+00:00` are
     * the same instant.
     *
     * This is the single named home for the "stored timestamp shape" knowledge; call sites read
     * `PointInTime::fromStorage($row['recorded_at'])` rather than the opaque
     * `fromDateTime(new DateTimeImmutable(...))`. Use `from()` when the string is already canonical.
     *
     * @throws InvalidDateTimeException when the raw value does not match the storage shape, names a
     *                                  rolled-over instant, or defensively when it cannot be parsed
     */
    public static function fromStorage(string $raw): self
    {
        if (preg_match(self::STORAGE_REGEX, $raw, $parts) !== 1) {
            throw InvalidDateTimeException::invalidFormat($raw, 'Y-m-d[ T]H:i:s[.u] with a mandatory offset');
        }

        // The semantic gate validates offset components, including historical offset seconds. The parser
        // happily computes an instant from an absurd `+24` or `+02:99`, and RFC 3339's negative
        // zero, `-00` or `-00:00`, asserts "local offset unknown" rather than UTC, which the
        // canonical constructor already refuses. Real drivers stay within -12:00..+14:00, so an
        // hour beyond 14, an impossible minute or second, or that negative zero is a corrupt
        // persisted value, refused like every other one.
        if ($parts['offset'] !== 'Z' && preg_match('/^(?<sign>[+-])(?<hours>\d{2})(?::?(?<minutes>\d{2})(?::(?<seconds>\d{2}))?)?$/', $parts['offset'], $offset) === 1) {
            $hours = (int) $offset['hours'];
            $minutes = (int) ($offset['minutes'] ?? '0');
            $seconds = (int) ($offset['seconds'] ?? '0');

            if ($hours > 14 || $minutes > 59 || $seconds > 59 || ($offset['sign'] === '-' && $hours === 0 && $minutes === 0 && $seconds === 0)) {
                throw InvalidDateTimeException::outOfRange($raw);
            }
        }

        try {
            $parsed = new DateTimeImmutable($raw);
            // unreachable in practice: the parser accepts every string the shape gate lets through,
            // even out-of-range offsets; kept so no native exception ever escapes this boundary
            // @codeCoverageIgnoreStart
        } catch (DateMalformedStringException $e) {
            throw InvalidDateTimeException::unparseable($raw, $e);
        }
        // @codeCoverageIgnoreEnd

        // The lenient parser corrects an out-of-range component by rolling it over, for example Feb 30
        // becomes Mar 1, instead of failing. The parsed value keeps the raw offset, so every raw
        // component must round-trip identically; a mismatch means the raw text named a nonexistent instant.
        if ($parsed->format('Y-m-d H:i:s') !== $parts['date'].' '.$parts['time']
            || $parsed->format('u') !== str_pad($parts['fraction'], 6, '0')) {
            throw InvalidDateTimeException::outOfRange($raw);
        }

        return self::fromDateTime($parsed);
    }

    /**
     * Not supported: a PointInTime is always UTC by contract, so a timezone change cannot produce
     * one. For a local-time view, leave the type first with
     * `DateTimeImmutable::createFromInterface($point)->setTimezone($tz)`, then come back through
     * `fromDateTime()` when done.
     *
     * @throws ClockException always
     */
    #[Override]
    public function setTimezone(DateTimeZone $timezone): never
    {
        throw ClockException::cannotLeaveUtc();
    }

    /**
     * {@inheritDoc}
     *
     * Create from a format-parsed string and normalize the parsed instant to UTC. Returns `false`
     * when parsing reports an error or warning, including a component rollover, or raises
     * `ValueError`.
     *
     * @throws InvalidDateTimeException when the parsed value has no canonical form, for example a year beyond 9999
     */
    #[Override]
    public static function createFromFormat(string $format, string $datetime, ?DateTimeZone $timezone = null): static|false
    {
        try {
            $parsed = DateTimeImmutable::createFromFormat($format, $datetime, $timezone);
        } catch (ValueError) {
            return false;
        }

        // `getLastErrors()` returns `false` exactly when the parse reported neither warning nor error,
        // and a parse error already lands in `$parsed === false`; a rollover reaches here as a warning.
        if ($parsed === false || DateTimeImmutable::getLastErrors() !== false) {
            return false;
        }

        return self::fromDateTime($parsed);
    }

    /**
     * {@inheritDoc}
     *
     * Create from a Unix timestamp.
     *
     * @throws InvalidDateTimeException when the instant has no canonical form: a non-finite or
     *                                  out-of-range timestamp, wrapped from the native `DateRangeError`
     *                                  so no native failure type escapes, or a year beyond 9999
     */
    #[Override]
    public static function createFromTimestamp(int|float $timestamp): static
    {
        try {
            $parsed = DateTimeImmutable::createFromTimestamp($timestamp);
        } catch (DateRangeError $e) {
            throw InvalidDateTimeException::unparseable((string) $timestamp, $e);
        }

        return self::fromDateTime($parsed);
    }

    /**
     * {@inheritDoc}
     *
     * Create from a mutable DateTime.
     *
     * @throws InvalidDateTimeException when the value has no canonical form, for example a year beyond 9999
     */
    #[Override]
    public static function createFromMutable(DateTime $object): static
    {
        return self::fromDateTime($object);
    }

    /**
     * {@inheritDoc}
     *
     * Create from any DateTimeInterface, the same conversion as `fromDateTime()`.
     *
     * @throws InvalidDateTimeException when the value has no canonical form, for example a year beyond 9999
     */
    #[Override]
    public static function createFromInterface(DateTimeInterface $object): static
    {
        return self::fromDateTime($object);
    }

    /**
     * {@inheritDoc}
     *
     * Apply a relative modifier, wrapping the native parse failure so no native exception leaves
     * the type. The timezone is untouched: a modifier cannot move the value out of UTC, and
     * timezone tokens inside the modifier string are ignored by the native implementation.
     *
     * An absolute modifier naming a nonexistent instant, Feb 30 or hour 24, is refused instead of
     * being rolled over. Relative arithmetic keeps its native overflow: `+1 month` on January 31st
     * still rolls into March.
     *
     * @throws InvalidDateTimeException when the modifier cannot be parsed, names a rolled-over
     *                                  instant, or computes a year outside the storable range
     */
    #[Override]
    public function modify(string $modifier): static
    {
        try {
            $modified = parent::modify($modifier);
        } catch (DateMalformedStringException $e) {
            throw InvalidDateTimeException::unparseable($modifier, $e);
        }

        // the parser flags an out-of-range absolute component rather than failing on it, then rolls
        // it over; the relative forms report nothing, so the flag discriminates a corrupt modifier
        // from a legitimate overflow
        if (DateTimeImmutable::getLastErrors() !== false) {
            throw InvalidDateTimeException::outOfRange($modifier);
        }

        return $this->storable($modified);
    }

    /**
     * {@inheritDoc}
     *
     * @throws InvalidDateTimeException when the computed instant leaves the storable year range
     */
    #[Override]
    public function add(DateInterval $interval): static
    {
        return $this->storable(parent::add($interval));
    }

    /**
     * {@inheritDoc}
     *
     * @throws InvalidDateTimeException when native subtraction rejects the interval or the computed
     *                                  instant leaves the storable year range
     */
    #[Override]
    public function sub(DateInterval $interval): static
    {
        try {
            $subtracted = parent::sub($interval);
        } catch (DateInvalidOperationException $e) {
            throw new InvalidDateTimeException('Cannot subtract date interval: '.$e->getMessage(), previous: $e);
        }

        return $this->storable($subtracted);
    }

    /**
     * {@inheritDoc}
     *
     * The native setters, guarded the same way as the arithmetic: the result must stay in the
     * storable form, and the native failure types must not leave this class.
     *
     * @throws InvalidDateTimeException when the computed instant leaves the storable year range
     */
    #[Override]
    public function setDate(int $year, int $month, int $day): static
    {
        return $this->storable(parent::setDate($year, $month, $day));
    }

    /**
     * {@inheritDoc}
     *
     * @throws InvalidDateTimeException when the computed instant leaves the storable year range
     */
    #[Override]
    public function setISODate(int $year, int $week, int $dayOfWeek = 1): static
    {
        return $this->storable(parent::setISODate($year, $week, $dayOfWeek));
    }

    /**
     * {@inheritDoc}
     *
     * @throws InvalidDateTimeException when the computed instant leaves the storable year range
     */
    #[Override]
    public function setTimestamp(int $timestamp): static
    {
        return $this->storable(parent::setTimestamp($timestamp));
    }

    /**
     * {@inheritDoc}
     *
     * @throws InvalidDateTimeException when the computed instant leaves the storable year range;
     *                                  reachable at the very edge, where an out-of-range hour rolls
     *                                  the last storable day into year 10000
     */
    #[Override]
    public function setTime(int $hour, int $minute, int $second = 0, int $microsecond = 0): static
    {
        return $this->storable(parent::setTime($hour, $minute, $second, $microsecond));
    }

    /**
     * {@inheritDoc}
     *
     * @throws InvalidDateTimeException when the microsecond is out of range, wrapped from the
     *                                  native `DateRangeError` so no native error type escapes
     */
    #[Override]
    public function setMicrosecond(int $microsecond): static
    {
        try {
            return parent::setMicrosecond($microsecond);
        } catch (DateRangeError $e) {
            throw InvalidDateTimeException::unparseable((string) $microsecond, $e);
        }
    }

    /**
     * The form guard on every arithmetic exit: `modify()`, which all `add*`/`sub*` helpers route
     * through, and the native `add()`/`sub()`. A computed year outside 0001-9999 produces an object
     * really typed `PointInTime` that `from()` and `fromStorage()` both refuse; persisted, a timer
     * or deadline could never hydrate again, so the computation throws, never clamps, and the caller
     * bounds its amount.
     *
     * @throws InvalidDateTimeException when the computed year leaves the storable range
     */
    private function storable(self $computed): static
    {
        $year = (int) $computed->format('Y');

        if ($year < 1 || $year > 9999) {
            throw InvalidDateTimeException::unstorable($computed->format(self::FORMAT));
        }

        return $computed;
    }

    public function isBefore(self $other): bool
    {
        return $this < $other;
    }

    public function isAfter(self $other): bool
    {
        return $this > $other;
    }

    public function equals(self $other): bool
    {
        return $this == $other;
    }

    /**
     * The arithmetic contract is `int<1,max>`: static analysis enforces it on analyzed call sites,
     * and this guard defends runtime inputs, where zero would silently no-op and a negative amount
     * would silently invert the operation's direction.
     *
     * @throws InvalidDateTimeException
     */
    private static function assertPositiveAmount(int $amount): void
    {
        if ($amount < 1) {
            throw InvalidDateTimeException::nonPositiveAmount($amount);
        }
    }

    /**
     * @param  int<1,max>  $seconds
     *
     * @throws InvalidDateTimeException
     */
    public function addSeconds(int $seconds): self
    {
        self::assertPositiveAmount($seconds);

        return $this->modify("+$seconds seconds");
    }

    /**
     * @param  int<1,max>  $minutes
     *
     * @throws InvalidDateTimeException
     */
    public function addMinutes(int $minutes): self
    {
        self::assertPositiveAmount($minutes);

        return $this->modify("+$minutes minutes");
    }

    /**
     * @param  int<1,max>  $hours
     *
     * @throws InvalidDateTimeException
     */
    public function addHours(int $hours): self
    {
        self::assertPositiveAmount($hours);

        return $this->modify("+$hours hours");
    }

    /**
     * @param  int<1,max>  $days
     *
     * @throws InvalidDateTimeException
     */
    public function addDays(int $days): self
    {
        self::assertPositiveAmount($days);

        return $this->modify("+$days days");
    }

    /**
     * @param  int<1,max>  $seconds
     *
     * @throws InvalidDateTimeException
     */
    public function subSeconds(int $seconds): self
    {
        self::assertPositiveAmount($seconds);

        return $this->modify("-$seconds seconds");
    }

    /**
     * @param  int<1,max>  $minutes
     *
     * @throws InvalidDateTimeException
     */
    public function subMinutes(int $minutes): self
    {
        self::assertPositiveAmount($minutes);

        return $this->modify("-$minutes minutes");
    }

    /**
     * @param  int<1,max>  $hours
     *
     * @throws InvalidDateTimeException
     */
    public function subHours(int $hours): self
    {
        self::assertPositiveAmount($hours);

        return $this->modify("-$hours hours");
    }

    /**
     * @param  int<1,max>  $days
     *
     * @throws InvalidDateTimeException
     */
    public function subDays(int $days): self
    {
        self::assertPositiveAmount($days);

        return $this->modify("-$days days");
    }

    public function toString(): string
    {
        return $this->format(self::FORMAT);
    }

    #[Override]
    public function __toString(): string
    {
        return $this->toString();
    }
}
