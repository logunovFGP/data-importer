<?php

declare(strict_types=1);

namespace App\Services\Shared\Support;

/**
 * Centralized boolean parsing for string/mixed values from configuration,
 * session, and form input. Recognizes common truthy/falsy representations.
 */
class BooleanParser
{
    private const array TRUE_VALUES  = ['1', 'true', 'on', 'yes'];
    private const array FALSE_VALUES = ['0', 'false', 'off', 'no', ''];

    /**
     * Parse a mixed value into a boolean.
     *
     * Recognizes true/1/on/yes as true and false/0/off/no/'' as false.
     * For unrecognized values, falls back to PHP's native (bool) cast.
     */
    public static function parse(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        if (in_array($normalized, self::TRUE_VALUES, true)) {
            return true;
        }

        if (in_array($normalized, self::FALSE_VALUES, true)) {
            return false;
        }

        return (bool) $value;
    }
}
