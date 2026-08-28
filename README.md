# Storm Clock

Strict UTC clock with microsecond precision: a PSR-20 clock whose every value is a `PointInTime` —
RFC 3339, explicit UTC (`Z` or `+00:00`), exactly six fractional digits, validated by one regex on
construction. The strictness is the point: one canonical text form guarantees consistent datetime
comparisons across the framework, the event store, and PostgreSQL.

## Install

```bash
composer require chronhub/storm-clock
```

## What's inside

| Class | Role |
|-------|------|
| `Clock` (Contracts) | Storm interface extending PSR-20 `ClockInterface`, with the `PointInTime` guarantee |
| `PointInTime` | Immutable datetime value object (extends `DateTimeImmutable`) |
| `SystemClock` | Production clock — decorates any PSR-20 clock, normalizes to UTC |
| `FrozenClock` | Deterministic, mutable clock for tests (`at`, `fromNow`, `advance*`, `rewind`, `moveTo`) |
| `ClockAssertion` | The RFC 3339 validator: format regex + the accepted UTC variants |
| `Duration` | A strictly positive retention span parsed from the compact CLI form (`30d`, `48h`, `90m`) the maintenance commands take |

## PointInTime

```php
use Storm\Clock\PointInTime;

$point = PointInTime::from('2024-01-01T10:00:00.000000Z');  // strict RFC 3339
$point = new PointInTime('now');                            // the one relaxed input

$point->isBefore($other);   $point->isAfter($other);   $point->equals($other);
$later = $point->addSeconds(30)->addMinutes(15)->addHours(2)->addDays(3);   // always a new instance

echo $point;                 // 2024-01-01T10:00:00.000000+00:00
echo $point->format('Y-m-d');  // native methods stay available when they preserve UTC
```

Rejected loudly (`InvalidDateTimeException`): missing or short fractional digits, any non-UTC
offset, `-00:00` (RFC 3339 reads it as "offset unknown", not UTC), a missing designator, a space
separator, natural language.

**The type never leaves UTC.** The routes that would leak another timezone are closed:
`setTimezone()` throws, and the native `createFrom*` factories renormalize to UTC through the
canonical constructor, instant preserved. For a local-time *view*, leave the type explicitly:

```php
$local = DateTimeImmutable::createFromInterface($point)->setTimezone($tz);
```

**No route accepts a rolled-over instant.** PHP's parser corrects an out-of-range component instead
of failing, turning `2026-02-30` into March 2nd. The two lenient routes are closed:
`createFromFormat()` returns `false` on any parser error or warning, and `modify()` throws on an
absolute modifier naming one. Relative arithmetic keeps its native overflow, so `+1 month` on
January 31st still rolls into March.

**Raw database strings go through `fromStorage()`.** `TIMESTAMPTZ(6)` bounds the STORED precision,
but the text a driver renders is not padded — the fraction can carry one to six digits or be absent,
and the offset comes short (`2026-06-03 12:21:57.2+00`). `PointInTime::fromStorage()` is the
designated boundary: it accepts exactly the shapes a driver emits and normalizes to the canonical
form. Feeding a raw DB string to the strict constructor trades that gate for a parse error on the
first trimmed fraction.

## SystemClock

Decorates any PSR-20 clock and normalizes its output silently — a timezone or precision difference
is corrected, never an error. Its only failure is `InvalidDateTimeException`, when the inner value
has no canonical representation at all (a year beyond 9999).

```php
use Storm\Clock\SystemClock;
use Symfony\Component\Clock\Clock;

$clock = new SystemClock(new Clock());
$now = $clock->now();   // PointInTime, always UTC
```

## FrozenClock (tests)

```php
$clock = FrozenClock::at('2024-01-01T10:00:00.000000Z');
$clock->advance(30);            // +30 s — the clock is mutable, the PointInTime it yields is not
$clock->moveTo('2024-06-01T08:00:00.000000Z');
```

## Wiring

Register `SystemClock` as a decorator and inject the PSR-20 interface everywhere; swap a
`FrozenClock` in tests:

```yaml
services:
    Storm\Clock\SystemClock:
        decorates: Psr\Clock\ClockInterface
        arguments:
            $inner: '@Storm\Clock\SystemClock.inner'
```

## Design decisions

- **`PointInTime` extends `DateTimeImmutable`** — PSR-20 compatibility for free; the UTC invariant
  is enforced by closing the escape routes, not by hiding the native API.
- **One validator, one exception** — `ClockAssertion` checks format, UTC and precision in a single
  regex pass; `InvalidDateTimeException` covers every refusal. The regex and the accepted UTC
  variant names are published as constants on `ClockAssertion` for Doctrine types and tests.
- **`SystemClock` normalizes, `PointInTime` refuses** — the clock absorbs the world's timezones;
  the value object never does.
- **`FrozenClock` is mutable by design** — it simulates time progressing in tests while every value
  it hands out stays immutable.
- **New helpers must earn their contract** — add one only when at least two production calls repeat
  the calculation or an existing call is fragile. Its behavior must be independent of business
  timezone, locale, external calendars and the system clock; sign, bounds, precision and rounding
  must be explicit, and adversarial tests must cover applicable calendar and storable-year edges.

## Tests

```bash
vendor/bin/phpunit src/Clock/Tests   # from the storm root
```

## Resources

This package is developed in the `chronhub/storm` monorepo; a standalone repository for it is a
READ-ONLY subtree split. Report issues and open pull requests on the monorepo, where the tests,
the architecture gates and the full internal documentation live.

---

*Pre-version: this package changes without deprecation cycles — pin a commit if you need
stability, expect resets rather than migrations until the first tagged version.*
