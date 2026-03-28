<?php

declare(strict_types=1);

namespace App\Services\TRC20\Support;

class TRC20TokenFilter
{
    /**
     * Check if a TronGrid transaction row is a USDT transfer.
     *
     * Reads TronGrid's `token_info.symbol` and `token_info.address` fields.
     * Falls back to flat fields for backward compatibility.
     */
    public static function isUSDT(array $row): bool
    {
        $symbol = strtoupper(trim((string)(
            $row['token_info']['symbol']
            ?? $row['token_symbol']
            ?? $row['currency']
            ?? $row['token']
            ?? $row['symbol']
            ?? ''
        )));

        if ('' !== $symbol && $symbol !== TRC20Constants::CURRENCY_USDT) {
            return false;
        }

        $contract = strtolower(trim((string)(
            $row['token_info']['address']
            ?? $row['token_contract']
            ?? $row['token_id']
            ?? $row['contract_address']
            ?? ''
        )));

        $configuredContract = strtolower(trim((string)config('trc20.usdt_contract_address', '')));

        if ('' === $configuredContract) {
            return true;
        }
        if ('' === $contract) {
            return '' === $symbol || $symbol === TRC20Constants::CURRENCY_USDT;
        }

        return $contract === $configuredContract;
    }

    /**
     * Extract the token contract address from a TronGrid row.
     */
    public static function extractContract(array $row): string
    {
        $tokenInfo = $row['token_info'] ?? $row['tokenInfo'] ?? [];
        if (!is_array($tokenInfo)) {
            $tokenInfo = [];
        }

        return strtolower(trim((string)(
            $row['token_contract']
            ?? $tokenInfo['address']
            ?? $tokenInfo['tokenId']
            ?? $row['token_id']
            ?? $row['contract_address']
            ?? ''
        )));
    }
}
