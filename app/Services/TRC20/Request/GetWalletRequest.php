<?php

declare(strict_types=1);

namespace App\Services\TRC20\Request;

use App\Services\Shared\Request\BearerJsonRequest;
use App\Services\TRC20\Support\TRC20AddressValidator;
use Illuminate\Support\Facades\Log;

class GetWalletRequest extends BearerJsonRequest
{
    private const string CURRENCY = 'USDT';

    public function __construct(
        private readonly string $apiKey,
        private string $wallet
    ) {
        parent::__construct((string)config('trc20.api_url'), $this->apiKey);
        $this->wallet = trim($wallet);
    }

    /**
     * Fetch details for one wallet from TronGrid.
     *
     * @return array<string, mixed>
     */
    public function get(): array
    {
        $wallet = trim($this->wallet);
        if (!TRC20AddressValidator::isValid($wallet)) {
            return [];
        }

        if (true === config('importer.fake_data')) {
            return $this->normalizeWallet(
                [
                    'address' => $wallet,
                    'name'    => $wallet,
                ]
            ) ?? [];
        }

        // TronGrid: GET /v1/accounts/{address}
        $endpoint = sprintf((string)config('trc20.wallets_endpoint'), $wallet);

        Log::debug(sprintf('TRC20: fetching wallet info for %s from TronGrid endpoint: %s', $wallet, $endpoint));

        $payload = $this->getJson($endpoint, $this->requestHeaders(), []);

        if (isset($payload['success']) && false === $payload['success']) {
            $errorMsg = $payload['error'] ?? $payload['statusMessage'] ?? 'Unknown TronGrid error';
            Log::error(sprintf('TRC20: TronGrid API error for wallet %s: %s', $wallet, $errorMsg));

            return [];
        }

        // TronGrid returns { data: [ { address: ..., balance: ..., trc20: [...] } ] }
        $data = $payload['data'] ?? [];
        if (!is_array($data) || 0 === count($data)) {
            // Account exists on chain but has no activity — still valid
            Log::info(sprintf('TRC20: wallet %s returned empty data from TronGrid — treating as valid with zero balance.', $wallet));

            return $this->normalizeWallet(['address' => $wallet]) ?? [];
        }

        $accountData = is_array($data[0] ?? null) ? $data[0] : $data;

        return $this->normalizeWallet($accountData) ?? [];
    }

    private function requestHeaders(): array
    {
        return [
            'TRON-PRO-API-KEY' => $this->apiKey,
        ];
    }

    private function normalizeWallet(array $row): ?array
    {
        $walletId = trim((string)($row['address'] ?? ''));
        if ('' === $walletId) {
            return null;
        }

        // Extract USDT balance from TronGrid trc20 array if available
        $usdtBalance = '0';
        $trc20Tokens = $row['trc20'] ?? [];
        if (is_array($trc20Tokens)) {
            $usdtContract = strtolower(trim((string)config('trc20.usdt_contract_address', '')));
            foreach ($trc20Tokens as $token) {
                if (is_array($token)) {
                    foreach ($token as $contractAddr => $balance) {
                        if ('' !== $usdtContract && strtolower($contractAddr) === $usdtContract) {
                            $usdtBalance = (string)$balance;
                        }
                    }
                }
            }
        }

        return [
            'id'                => $walletId,
            'name'              => (string)($row['name'] ?? $walletId),
            'institution_name'  => 'TRC20',
            'institution_logo'  => '',
            'provider'          => 'trc20',
            'currency'          => self::CURRENCY,
            'status'            => 'active',
            'balance'           => $usdtBalance,
        ];
    }
}
