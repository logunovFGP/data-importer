<?php

declare(strict_types=1);

namespace App\Services\Shared\Support;

class CurrencyCode
{
    private const array NUMERIC_TO_ALPHA = [
        '840' => 'USD',
        '978' => 'EUR',
        '826' => 'GBP',
        '643' => 'RUB',
        '981' => 'GEL',
        '756' => 'CHF',
    ];

    public static function normalize(?string $raw): string
    {
        $normalized = self::normalizeOrEmpty($raw);
        if ('' === $normalized) {
            return 'EUR';
        }

        return $normalized;
    }

    /**
     * Given an array of currency strings, return the most frequently occurring one.
     * Returns '' if the array is empty or contains no valid currencies.
     */
    public static function selectDominant(array $currencies): string
    {
        if ([] === $currencies) {
            return '';
        }

        $counter = [];
        foreach ($currencies as $currency) {
            if (!is_string($currency) || '' === trim($currency)) {
                continue;
            }
            $normalized = self::normalizeOrEmpty($currency);
            if ('' === $normalized) {
                continue;
            }
            $counter[$normalized] = ($counter[$normalized] ?? 0) + 1;
        }
        if ([] === $counter) {
            return '';
        }
        arsort($counter);
        $best = array_key_first($counter);

        return is_string($best) ? $best : '';
    }

    public static function normalizeOrEmpty(?string $raw): string
    {
        $value = strtoupper(trim((string)$raw));
        if ('' === $value) {
            return '';
        }

        // Accept 3-5 character alphabetic codes (ISO 4217 + crypto symbols like USDT, WTRX)
        $len = strlen($value);
        if ($len >= 3 && $len <= 5 && ctype_alpha($value)) {
            return $value;
        }

        if (array_key_exists($value, self::NUMERIC_TO_ALPHA)) {
            return self::NUMERIC_TO_ALPHA[$value];
        }

        return '';
    }
}
