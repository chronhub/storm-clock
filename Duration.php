<?php

declare(strict_types=1);

namespace Storm\Clock;

/**
 * A retention or age window parsed from a compact CLI form such as `30d`, `48h` or `90m`. It backs
 * the `--before` of the prune and cleanup maintenance commands and the `--idle-for` of the saga
 * listing. `fromString` returns null on malformed input so the command fails with a clear message
 * rather than guessing; `seconds` feeds the SQL age comparison.
 *
 * A duration is always strictly positive: `0d` is rejected like any malformed input, because a
 * zero retention would turn a prune guard into a prune-all.
 */
final readonly class Duration
{
    private function __construct(
        public int $seconds,
    ) {}

    /**
     * Parse a `<n>d`, `<n>h` or `<n>m` form for days, hours or minutes to a `Duration`; null when
     * malformed, zero, or too large to hold as seconds in an int.
     */
    public static function fromString(string $value): ?self
    {
        if (preg_match('/^(\d+)\s*([dhm])$/i', trim($value), $matches) !== 1) {
            return null;
        }

        $unit = match (strtolower($matches[2])) {
            'h' => 3600,
            'm' => 60,
            default => 86400, // 'd': the regex [dhm] guarantees one of d/h/m
        };

        // The int cast of an over-long digit string saturates at PHP_INT_MAX; the upper bound
        // below catches that saturation together with a genuine multiplication overflow.
        // Two equivalent mutants stay counted here: `$matches[0]` starts with the same digits,
        // which the cast reads alone, and an uncast numeric string compares and multiplies like
        // its integer; an ignore would mask the killed `$matches[2]` sibling.
        $amount = (int) $matches[1];
        if ($amount < 1 || $amount > intdiv(PHP_INT_MAX, $unit)) {
            return null;
        }

        return new self($amount * $unit);
    }
}
