<?php

declare(strict_types=1);

namespace App\Services\TRC20\Support;

class TRC20AmountParser
{
    /**
     * Parse a TronGrid amount value (in sun) to a human-readable decimal string.
     *
     * Uses bcmath to avoid float precision loss for financial amounts.
     * TronGrid returns `value` as a string in the smallest unit (e.g., 1000000 = 1.000000 USDT).
     *
     * @return string|null Decimal string (e.g., "1234.567890") or null if unparseable
     */
    public static function parse(array $row): ?string
    {
        $value = (string)($row['value'] ?? $row['amount'] ?? '');
        if ('' === trim($value) || !is_numeric($value)) {
            return null;
        }

        // Guard against scientific notation (e.g., "1.0E-5", "1E5") which bcmath rejects.
        // Must run BEFORE any bcmath operation.
        if (preg_match('/[eE]/', $value)) {
            $value = sprintf('%.12f', (float)$value);
        }

        $decimals = (int)($row['token_info']['decimals'] ?? $row['decimals'] ?? TRC20Constants::USDT_DECIMALS);

        if ($decimals > 0 && !str_contains($value, '.')) {
            return bcdiv($value, bcpow('10', (string)$decimals), 12);
        }

        return $value;
    }

    /**
     * Parse amount and return as float for backward compatibility.
     * Prefer parse() for precision-sensitive operations.
     */
    public static function parseAsFloat(array $row): ?float
    {
        $result = self::parse($row);

        return null === $result ? null : (float)$result;
    }
}
