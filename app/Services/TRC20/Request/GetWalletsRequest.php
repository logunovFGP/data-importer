<?php

declare(strict_types=1);

namespace App\Services\TRC20\Request;

use App\Exceptions\ImporterHttpException;
use App\Services\LunchFlow\Response\GetAccountsResponse;
use App\Services\Shared\Request\BearerJsonRequest;
use App\Services\TRC20\Support\TRC20AddressValidator;

class GetWalletsRequest extends BearerJsonRequest
{
    private const string CURRENCY = 'USDT';
    private int $pageSize;
    private int $maxPages;

    public function __construct(
        private readonly string $apiKey,
        private readonly array $wallets,
        ?int $pageSize = null,
        ?int $maxPages = null
    ) {
        parent::__construct((string)config('trc20.api_url'), $this->apiKey);
        $pageSizeConfig   = $pageSize ?? (int)config('trc20.page_size', 100);
        $maxPagesConfig = $maxPages ?? (int)config('trc20.max_pages', 100);

        $this->pageSize = max(1, $pageSizeConfig);
        $this->maxPages = max(1, $maxPagesConfig);
    }

    public function get(): GetAccountsResponse
    {
        $wallets = $this->normalizeWallets($this->wallets);
        if (0 === count($wallets)) {
            return new GetAccountsResponse([]);
        }

        $this->setTimeOut((float)config('importer.connection.timeout'));

        if (true === config('importer.fake_data')) {
            $fallback = $wallets;
            $first    = $fallback[0];

            return new GetAccountsResponse(
                [
                    [
                        'id'               => $first,
                        'name'             => $first,
                        'institution_name'  => 'TRC20',
                        'institution_logo'  => '',
                        'provider'         => 'trc20',
                        'currency'         => self::CURRENCY,
                        'status'           => 'active',
                    ],
                ]
            );
        }

        $resultWallets  = [];
        $remainingWallets = $wallets;
        $cursor          = null;

        for ($page = 0; $page < $this->maxPages && 0 < count($remainingWallets); $page++) {
            $payload = $this->getJson(
                (string)config('trc20.wallets_endpoint'),
                $this->requestHeaders(),
                $this->buildQuery($remainingWallets, $cursor)
            );
            $rows    = $this->extractRows($payload);
            if ([] === $rows) {
                break;
            }

            foreach ($rows as $row) {
                $normalized = $this->normalizeWallet($row);
                if (null === $normalized) {
                    continue;
                }

                $matchedWallet = TRC20AddressValidator::findMatchingWallet($normalized['id'], $remainingWallets);
                if (null === $matchedWallet) {
                    continue;
                }

                $resultWallets[] = $normalized;
                $remainingWallets = array_values(array_diff($remainingWallets, [$matchedWallet]));
            }

            if (0 === count($remainingWallets)) {
                break;
            }
            if (count($rows) < $this->pageSize) {
                break;
            }

            $nextCursor = $this->extractCursor($payload);
            if (null === $nextCursor || $nextCursor === $cursor) {
                break;
            }
            $cursor = $nextCursor;
        }

        foreach ($remainingWallets as $wallet) {
            $request  = new GetWalletRequest($this->apiKey, $wallet);
            $request->setTimeOut((float)config('importer.connection.timeout'));
            $single   = $request->get();
            if ([] !== $single) {
                $resultWallets[] = $single;
            }
        }

        return new GetAccountsResponse($resultWallets);
    }

    private function buildQuery(array $wallets, ?string $cursor): array
    {
        $query = [
            'address' => implode(',', $wallets),
            'token'   => self::CURRENCY,
            'limit'   => $this->pageSize,
        ];

        if (null !== $cursor && '' !== $cursor) {
            $query['cursor'] = $cursor;
        }

        return $query;
    }

    private function extractRows(array $payload): array
    {
        if (isset($payload['data']) && is_array($payload['data']) && array_is_list($payload['data'])) {
            return $payload['data'];
        }

        if (isset($payload['wallets']) && is_array($payload['wallets']) && array_is_list($payload['wallets'])) {
            return $payload['wallets'];
        }

        if (array_is_list($payload)) {
            return $payload;
        }

        return [];
    }

    private function extractCursor(array $payload): ?string
    {
        if (isset($payload['nextCursor']) && '' !== trim((string)$payload['nextCursor'])) {
            return (string)$payload['nextCursor'];
        }

        if (isset($payload['cursor']) && '' !== trim((string)$payload['cursor'])) {
            return (string)$payload['cursor'];
        }

        return null;
    }

    private function normalizeWallet(array $row): ?array
    {
        $walletId = trim((string)($row['address'] ?? $row['addressHex'] ?? $row['addressList'] ?? $row['id'] ?? ''));
        if ('' === $walletId || !TRC20AddressValidator::isValid($walletId)) {
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

    private function normalizeWallets(array $wallets): array
    {
        $normalized = [];
        $invalid    = [];
        foreach ($wallets as $wallet) {
            $value = trim((string)$wallet);
            if (!TRC20AddressValidator::isValid($value)) {
                $invalid[] = $value;

                continue;
            }
            if ('' !== $value && !in_array($value, $normalized, true)) {
                $normalized[] = $value;
            }
        }

        if (0 !== count($invalid)) {
            $invalid = array_values(array_unique(array_filter($invalid, static fn(string $value): bool => '' !== $value)));
            if (0 !== count($invalid)) {
                throw new ImporterHttpException(sprintf('Invalid TRC20 wallet format(s): %s', implode(', ', $invalid)));
            }
        }

        return $normalized;
    }

    private function requestHeaders(): array
    {
        return [
            'TRON-PRO-API-KEY' => $this->apiKey,
        ];
    }
}
