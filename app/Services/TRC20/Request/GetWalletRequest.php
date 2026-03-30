<?php

declare(strict_types=1);

namespace App\Services\TRC20\Request;

use App\Services\Shared\Request\BearerJsonRequest;
use App\Services\TRC20\Support\TRC20AddressValidator;
use App\Services\TRC20\Support\TRC20Constants;
use App\Services\TRC20\Support\TRC20TokenFilter;
use Illuminate\Support\Facades\Log;

class GetWalletRequest extends BearerJsonRequest
{
    use TRC20RequestTrait;

    public function __construct(
        private readonly string $apiKey,
        private string $wallet
    ) {
        parent::__construct((string)config('trc20.api_url'), $this->apiKey);
        $this->wallet = trim($wallet);
    }

    /**
     * Fetch wallet details from TronGrid and return one service account per token.
     *
     * Returns: array of accounts, one per token type found on the wallet.
     * Naming: [SYMBOL] wallet_address
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAccounts(): array
    {
        $wallet = trim($this->wallet);
        if (!TRC20AddressValidator::isValid($wallet)) {
            return [];
        }

        if (true === config('importer.fake_data')) {
            return [
                $this->buildAccount($wallet, TRC20Constants::CURRENCY_TRX, 'TRON', TRC20Constants::TRX_DECIMALS, '0'),
                $this->buildAccount($wallet, TRC20Constants::CURRENCY_USDT, 'Tether USD', TRC20Constants::USDT_DECIMALS, '0'),
            ];
        }

        $endpoint = sprintf((string)config('trc20.wallets_endpoint'), $wallet);
        Log::debug(sprintf('TRC20: fetching wallet info for %s from TronGrid endpoint: %s', $wallet, $endpoint));

        $payload = $this->getJson($endpoint, $this->requestHeaders(), []);

        if (isset($payload['success']) && false === $payload['success']) {
            $errorMsg = $payload['error'] ?? $payload['statusMessage'] ?? 'Unknown TronGrid error';
            Log::error(sprintf('TRC20: TronGrid API error for wallet %s: %s', $wallet, $errorMsg));

            return [];
        }

        $data = $payload['data'] ?? [];
        if (!is_array($data) || 0 === count($data)) {
            Log::info(sprintf('TRC20: wallet %s returned empty data from TronGrid — creating default TRX account.', $wallet));

            return [$this->buildAccount($wallet, TRC20Constants::CURRENCY_TRX, 'TRON', TRC20Constants::TRX_DECIMALS, '0')];
        }

        $accountData = is_array($data[0] ?? null) ? $data[0] : $data;

        return $this->extractTokenAccounts($wallet, $accountData);
    }

    /**
     * Parse the TronGrid account response and create one service account per token.
     */
    private function extractTokenAccounts(string $wallet, array $accountData): array
    {
        $accounts = [];

        // 1. Native TRX balance
        if (TRC20TokenFilter::isTokenInSupportedList(TRC20Constants::CURRENCY_TRX)) {
            $trxSun  = (string)($accountData['balance'] ?? '0');
            $trxBalance = bcdiv($trxSun, bcpow('10', (string)TRC20Constants::TRX_DECIMALS), TRC20Constants::TRX_DECIMALS);
            $accounts[] = $this->buildAccount($wallet, TRC20Constants::CURRENCY_TRX, 'TRON', TRC20Constants::TRX_DECIMALS, $trxBalance);
        }

        // 2. TRC20 tokens — resolve contract addresses to symbols via TronGrid
        $trc20Tokens = $accountData['trc20'] ?? [];
        if (is_array($trc20Tokens)) {
            foreach ($trc20Tokens as $tokenEntry) {
                if (!is_array($tokenEntry)) {
                    continue;
                }
                foreach ($tokenEntry as $contractAddr => $balanceSun) {
                    $tokenInfo = $this->resolveTokenInfo($wallet, (string)$contractAddr);
                    if (null === $tokenInfo) {
                        continue;
                    }

                    $symbol   = $tokenInfo['symbol'];
                    $name     = $tokenInfo['name'];
                    $decimals = $tokenInfo['decimals'];

                    if (!TRC20TokenFilter::isTokenInSupportedList($symbol)) {
                        continue;
                    }

                    $balance = bcdiv((string)$balanceSun, bcpow('10', (string)$decimals), $decimals);
                    $accounts[] = $this->buildAccount($wallet, $symbol, $name, $decimals, $balance);
                }
            }
        }

        if ([] === $accounts) {
            $accounts[] = $this->buildAccount($wallet, TRC20Constants::CURRENCY_TRX, 'TRON', TRC20Constants::TRX_DECIMALS, '0');
        }

        Log::debug(sprintf('TRC20: wallet %s has %d token account(s).', $wallet, count($accounts)));

        return $accounts;
    }

    /**
     * Resolve a TRC20 contract address to symbol/name/decimals by fetching one transaction.
     *
     * TronGrid's /v1/accounts/{address} returns TRC20 balances as {contract: balance}
     * but does NOT include symbol or decimals. We fetch one transaction for this contract
     * to get the token_info metadata.
     */
    private function resolveTokenInfo(string $wallet, string $contractAddress): ?array
    {
        $endpoint = sprintf((string)config('trc20.transactions_endpoint'), $wallet);
        try {
            $payload = $this->getJson($endpoint, $this->requestHeaders(), [
                'only_confirmed'   => 'true',
                'limit'            => 1,
                'contract_address' => $contractAddress,
            ]);
        } catch (\Throwable $e) {
            Log::warning(sprintf('TRC20: could not resolve token info for contract %s: %s', $contractAddress, $e->getMessage()));

            return null;
        }

        $rows = $payload['data'] ?? [];
        if (!is_array($rows) || 0 === count($rows)) {
            return null;
        }

        $tokenInfo = $rows[0]['token_info'] ?? null;
        if (!is_array($tokenInfo) || !isset($tokenInfo['symbol'])) {
            return null;
        }

        return [
            'symbol'   => strtoupper(trim((string)$tokenInfo['symbol'])),
            'name'     => trim((string)($tokenInfo['name'] ?? $tokenInfo['symbol'])),
            'decimals' => (int)($tokenInfo['decimals'] ?? 6),
        ];
    }

    private function buildAccount(string $wallet, string $symbol, string $name, int $decimals, string $balance): array
    {
        return [
            'id'                => sprintf('%s|%s', $wallet, $symbol),
            'name'              => sprintf('[%s] %s', $symbol, $wallet),
            'institution_name'  => 'TRC20',
            'institution_logo'  => '',
            'provider'          => 'trc20',
            'currency'          => $symbol,
            'currency_code'     => $symbol,
            'currency_name'     => $name,
            'currency_decimals' => $decimals,
            'status'            => 'active',
            'balance'           => $balance,
            'wallet'            => $wallet,
        ];
    }

    // requestHeaders() provided by TRC20RequestTrait
    // getSupportedTokens() and isTokenSupported() replaced by TRC20TokenFilter::getSupportedTokens()
    // and TRC20TokenFilter::isTokenInSupportedList()
}
