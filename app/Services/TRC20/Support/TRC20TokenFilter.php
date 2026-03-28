<?php

declare(strict_types=1);

namespace App\Services\TRC20\Support;

class TRC20TokenFilter
{
    /**
     * Check if a TronGrid transaction row contains a supported token.
     *
     * Checks against the `trc20.supported_tokens` config list.
     * If the list is empty or '*', all tokens are accepted.
     */
    public static function isSupported(array $row): bool
    {
        $symbol = self::extractSymbol($row);
        if ('' === $symbol) {
            return true;
        }

        $supportedTokens = self::getSupportedTokens();
        if ([] === $supportedTokens) {
            return true;
        }

        return in_array($symbol, $supportedTokens, true);
    }

    /**
     * Extract the token symbol from a TronGrid TRC20 transaction row.
     */
    public static function extractSymbol(array $row): string
    {
        return strtoupper(trim((string)(
            $row['token_info']['symbol']
            ?? $row['token_symbol']
            ?? $row['currency']
            ?? $row['token']
            ?? $row['symbol']
            ?? ''
        )));
    }

    /**
     * Extract the token name from a TronGrid TRC20 transaction row.
     */
    public static function extractName(array $row): string
    {
        return trim((string)(
            $row['token_info']['name']
            ?? $row['token_name']
            ?? ''
        ));
    }

    /**
     * Extract the token decimals from a TronGrid TRC20 transaction row.
     */
    public static function extractDecimals(array $row): int
    {
        return (int)($row['token_info']['decimals'] ?? $row['decimals'] ?? TRC20Constants::USDT_DECIMALS);
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

    /**
     * Get the list of supported token symbols from config.
     *
     * @return array<string> Uppercase symbols, or empty array for "accept all"
     */
    private static function getSupportedTokens(): array
    {
        $raw = (string)config('trc20.supported_tokens', '');
        if ('' === trim($raw) || '*' === trim($raw)) {
            return [];
        }

        $tokens = array_map('trim', explode(',', $raw));
        $tokens = array_filter($tokens, static fn(string $t): bool => '' !== $t);
        $tokens = array_map('strtoupper', $tokens);

        return array_values($tokens);
    }
}
