<?php

declare(strict_types=1);

namespace App\Services\TRC20\Support;

class TRC20AddressValidator
{
    public const string TRC20_ADDRESS_HEX = '/^(0x)?41[0-9a-f]{40}$/i';
    public const string TRC20_T_ADDRESS = '/^T[1-9A-HJ-NP-Za-km-z]{33}$/';

    public static function isValid(string $wallet): bool
    {
        $wallet = trim($wallet);
        if ('' === $wallet) {
            return false;
        }
        if (preg_match(self::TRC20_ADDRESS_HEX, $wallet) === 1) {
            return true;
        }

        return preg_match(self::TRC20_T_ADDRESS, $wallet) === 1;
    }

    public static function addressesMatch(string $left, string $right): bool
    {
        return $left !== '' && $right !== '' && strcasecmp($left, $right) === 0;
    }

    public static function walletInList(string $address, array $wallets): bool
    {
        foreach ($wallets as $wallet) {
            if (strcasecmp($address, $wallet) === 0) {
                return true;
            }
        }

        return false;
    }

    public static function findMatchingWallet(string $address, array $wallets): ?string
    {
        foreach ($wallets as $wallet) {
            if (strcasecmp($address, $wallet) === 0) {
                return $wallet;
            }
        }

        return null;
    }
}
