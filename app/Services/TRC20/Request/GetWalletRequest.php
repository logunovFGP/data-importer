<?php

declare(strict_types=1);

namespace App\Services\TRC20\Request;

use App\Services\Shared\Request\BearerJsonRequest;
use App\Services\TRC20\Support\TRC20AddressValidator;

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
     * Fetch details for one wallet.
     *
     * @return array<string, mixed>
     */
    public function get(): array
    {
        $wallet = trim($this->wallet);
        if (!TRC20AddressValidator::isValid($wallet)) {
            return [];
        }

        if ('' === $wallet) {
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

        $payload = $this->getJson(
            (string)config('trc20.wallets_endpoint'),
            $this->requestHeaders(),
            $this->buildQuery($wallet)
        );
        $rows    = $this->extractRows($payload);
        if ([] === $rows) {
            return [];
        }

        return $this->normalizeWallet($rows[0]) ?? [];
    }

    private function buildQuery(string $wallet): array
    {
        return [
            'address' => $wallet,
            'token'   => self::CURRENCY,
        ];
    }

    private function extractRows(array $payload): array
    {
        if (isset($payload['data']) && is_array($payload['data']) && array_is_list($payload['data'])) {
            return $payload['data'];
        }

        if (isset($payload['data']) && is_array($payload['data'])) {
        return array_filter([
                $payload['data'],
            ], is_array(...));
        }

        if (array_is_list($payload)) {
            return $payload;
        }

        return [];
    }

    private function requestHeaders(): array
    {
        return [
            'TRON-PRO-API-KEY' => $this->apiKey,
        ];
    }

    private function normalizeWallet(array $row): ?array
    {
        $walletId = trim((string)($row['address'] ?? $row['addressHex'] ?? $row['addressList'] ?? $row['id'] ?? ''));
        if ('' === $walletId) {
            return null;
        }

        return [
            'id'               => $walletId,
            'name'             => (string)($row['name'] ?? $row['address'] ?? $walletId),
            'institution_name'  => 'TRC20',
            'institution_logo'  => '',
            'provider'         => 'trc20',
            'currency'         => self::CURRENCY,
            'status'           => (string)($row['status'] ?? 'active'),
        ];
    }
}
