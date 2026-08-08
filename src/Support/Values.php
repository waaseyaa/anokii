<?php

declare(strict_types=1);

namespace Anokii\Support;

/**
 * Pure normalization of untyped values into the shapes the distribution
 * declares.
 *
 * Anokii reads a lot of genuinely untyped data: entity `_data` blobs
 * ({@see \Waaseyaa\Entity\EntityInterface::get()} returns mixed), raw SQL rows
 * (the driver yields mixed rows and may hand back numeric columns as strings),
 * decoded JSON request bodies, and parsed YAML config. A blanket `(string)` /
 * `(int)` cast over that data is both unsound to analyse and unsafe at runtime:
 * casting an array raises "Array to string conversion" and yields the literal
 * "Array", and casting an object either invokes an unexpected __toString() or
 * throws.
 *
 * These helpers convert scalars (and null) exactly as PHP would, and REJECT
 * arrays, objects, and resources by returning the caller's default. That is the
 * conservative reading for request-shaped input: an attacker who sends
 * `{"email": {"x": 1}}` gets the empty default, never a coerced string that a
 * later comparison might accept.
 *
 * Every method is pure and side-effect free; none of them mutate or inspect
 * anything beyond the value passed in.
 *
 * @internal
 */
final class Values
{
    /**
     * A string from a scalar or null; arrays, objects, and resources yield the
     * default. Scalar conversion matches PHP's own cast (`true` becomes "1",
     * `false` and null become the default).
     */
    public static function str(mixed $value, string $default = ''): string
    {
        if (\is_string($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value) || \is_bool($value)) {
            return (string) $value;
        }

        return $default;
    }

    /** {@see str()} with surrounding whitespace removed. */
    public static function trimmed(mixed $value, string $default = ''): string
    {
        return trim(self::str($value, $default));
    }

    /**
     * A string, or null when the value is absent or empty. Used where the
     * schema distinguishes "not set" from "set to the empty string".
     */
    public static function nullableStr(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $string = self::str($value);

        return $string === '' ? null : $string;
    }

    /**
     * An int from an int, bool, finite float, or numeric string; anything else
     * (including a non-numeric string such as "abc", an array, or an object)
     * yields the default. Numeric strings use PHP's defined integer conversion;
     * values outside the platform integer range clamp to that range.
     */
    public static function int(mixed $value, int $default = 0): int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (\is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (\is_float($value)) {
            return is_finite($value) ? (int) $value : $default;
        }
        if (\is_string($value)) {
            $trimmed = trim($value);

            return is_numeric($trimmed) ? (int) $trimmed : $default;
        }

        return $default;
    }

    /**
     * A float from an int, bool, finite float, or numeric string; anything else
     * yields the default.
     */
    public static function float(mixed $value, float $default = 0.0): float
    {
        if (\is_float($value)) {
            return is_finite($value) ? $value : $default;
        }
        if (\is_int($value)) {
            return (float) $value;
        }
        if (\is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }
        if (\is_string($value)) {
            $trimmed = trim($value);

            return is_numeric($trimmed) ? (float) $trimmed : $default;
        }

        return $default;
    }

    /**
     * A bool from a scalar, using PHP's truthiness for scalars only; arrays,
     * objects, and resources yield the default rather than the "non-empty array
     * is true" rule, which is meaningless for a flag field.
     */
    public static function bool(mixed $value, bool $default = false): bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value) || \is_string($value)) {
            return (bool) $value;
        }

        return $default;
    }

    /**
     * A string-keyed map from an array; a non-array yields []. Integer-keyed
     * entries are rejected so a JSON list cannot masquerade as an object map.
     *
     * @return array<string, mixed>
     */
    public static function map(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $key => $item) {
            if (\is_string($key)) {
                $out[$key] = $item;
            }
        }

        return $out;
    }

    /**
     * A list of string-keyed maps: every element of the input that is itself an
     * array, re-keyed by {@see map()}. Non-array elements are dropped.
     *
     * @return list<array<string, mixed>>
     */
    public static function mapList(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (\is_array($item)) {
                $out[] = self::map($item);
            }
        }

        return $out;
    }

    /**
     * A list of strings: every scalar element converted by {@see str()}.
     * Elements that are arrays, objects, or resources are DROPPED rather than
     * coerced, so a malformed entry can never masquerade as the empty-string
     * key (which some callers use as a lookup sentinel).
     *
     * @return list<string>
     */
    public static function stringList(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (\is_string($item) || \is_int($item) || \is_float($item) || \is_bool($item)) {
                $out[] = self::str($item);
            }
        }

        return $out;
    }
}
