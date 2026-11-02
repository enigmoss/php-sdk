<?php

declare(strict_types=1);

namespace Enigmoss\Internal;

use Enigmoss\Exception\InvalidArgumentException;

/**
 * Structural checks of request values. Only invariants that never change are checked here
 * (formats, not business limits): the server stays the source of truth for validation.
 *
 * @internal
 */
final class Assert
{
    private const UUID = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';

    public static function uuid(string $value, string $field): string
    {
        if (preg_match(self::UUID, $value) !== 1) {
            throw new InvalidArgumentException(\sprintf('%s must be a UUID, got "%s".', $field, $value));
        }

        return strtolower($value);
    }

    public static function orderId(string $value, string $field = 'orderId'): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,128}$/', $value) !== 1) {
            throw new InvalidArgumentException(\sprintf('%s must be 1-128 letters, digits, "-" or "_".', $field));
        }

        return $value;
    }

    /**
     * A decimal amount as a string. Floats are not accepted anywhere in the SDK: pass "10.50".
     */
    public static function amount(string $value, string $field = 'amount'): string
    {
        if (preg_match('/^\d{1,11}(\.\d{1,18})?$/', $value) !== 1) {
            throw new InvalidArgumentException(\sprintf('%s must be a positive decimal string such as "10.50", got "%s".', $field, $value));
        }

        return $value;
    }

    public static function length(?string $value, string $field, int $min, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        // Characters, not bytes, like the server; PCRE keeps the SDK free of ext-mbstring.
        $length = (int) preg_match_all('/./su', $value);
        if ($length < $min || $length > $max) {
            throw new InvalidArgumentException(\sprintf('%s must be %d-%d characters long.', $field, $min, $max));
        }

        return $value;
    }

    public static function range(?int $value, string $field, int $min, int $max): ?int
    {
        if ($value !== null && ($value < $min || $value > $max)) {
            throw new InvalidArgumentException(\sprintf('%s must be between %d and %d.', $field, $min, $max));
        }

        return $value;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param T|null $value
     *
     * @return T|null
     */
    public static function knownEnum(?\BackedEnum $value, string $field): ?\BackedEnum
    {
        if ($value !== null && $value->value === 'unknown') {
            throw new InvalidArgumentException(\sprintf('%s cannot be the Unknown case in a request.', $field));
        }

        return $value;
    }
}
