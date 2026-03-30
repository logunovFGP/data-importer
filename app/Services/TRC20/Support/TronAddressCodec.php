<?php

declare(strict_types=1);

namespace App\Services\TRC20\Support;

/**
 * Converts between TRON hex addresses (41-prefixed) and Base58Check format.
 * TronGrid native transaction API returns hex addresses; the rest of the
 * importer uses Base58Check (e.g., "TT65LR...").
 */
class TronAddressCodec
{
    /**
     * Convert a 41-prefixed hex address to Base58Check.
     * Returns empty string if input is invalid.
     */
    public static function hexToBase58(string $hex): string
    {
        $hex   = ltrim($hex, '0x');
        $bytes = @hex2bin($hex);
        if (false === $bytes || 21 !== strlen($bytes)) {
            return '';
        }

        $hash1    = hash('sha256', $bytes, true);
        $hash2    = hash('sha256', $hash1, true);
        $checksum = substr($hash2, 0, 4);

        return self::base58Encode($bytes . $checksum);
    }

    private static function base58Encode(string $data): string
    {
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $num      = '0';
        for ($i = 0; $i < strlen($data); $i++) {
            $num = bcmul($num, '256');
            $num = bcadd($num, (string)ord($data[$i]));
        }

        $encoded = '';
        while (bccomp($num, '0') > 0) {
            $rem     = bcmod($num, '58');
            $num     = bcdiv($num, '58', 0);
            $encoded = $alphabet[(int)$rem] . $encoded;
        }

        // Leading-zero prefix for generic Base58Check — TRON addresses always start with 0x41, so this loop is a no-op for valid TRON addresses.
        for ($i = 0; $i < strlen($data) && "\x00" === $data[$i]; $i++) {
            $encoded = '1' . $encoded;
        }

        return $encoded;
    }
}
