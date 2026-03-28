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

    public static function normalizeOrEmpty(?string $raw): string
    {
        $value = strtoupper(trim((string)$raw));
        if ('' === $value) {
            return '';
        }

        if (3 === strlen($value) && ctype_alpha($value)) {
            return $value;
        }

        if (array_key_exists($value, self::NUMERIC_TO_ALPHA)) {
            return self::NUMERIC_TO_ALPHA[$value];
        }

        return '';
    }
}
