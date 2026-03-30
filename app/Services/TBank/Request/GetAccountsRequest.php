<?php

declare(strict_types=1);

namespace App\Services\TBank\Request;

use App\Exceptions\ImporterHttpException;
use App\Services\LunchFlow\Response\GetAccountsResponse;
use App\Services\Shared\Support\CurrencyCode;
use Illuminate\Support\Facades\Log;

class GetAccountsRequest extends SessionJsonRequest
{
    public function __construct(string $sessionId, string $cookieHeader)
    {
        parent::__construct((string)config('tbank.api_url'), $sessionId, $cookieHeader);
    }

    /**
     * @throws ImporterHttpException
     */
    public function get(): GetAccountsResponse
    {
        if (true === config('importer.fake_data')) {
            return new GetAccountsResponse(
                [
                    [
                        'id'               => '5084302883',
                        'name'             => 'TBank Demo Account',
                        'institution_name' => 'TBank',
                        'institution_logo' => '',
                        'provider'         => 'tbank',
                        'currency'         => 'RUB',
                        'status'           => 'active',
                    ],
                ]
            );
        }

        $decoded    = $this->getJson((string)config('tbank.accounts_endpoint'), $this->buildBaseQuery());
        $rows       = $this->extractRows($decoded['payload'] ?? $decoded);
        $normalized = [];
        foreach ($rows as $row) {
            $account = $this->normalizeAccount($row);
            if (null === $account) {
                continue;
            }
            $normalized[$account['id']] = $account;
        }
        Log::debug(sprintf('TBank accounts request normalized %d unique account(s).', count($normalized)));

        return new GetAccountsResponse(array_values($normalized));
    }

    private function extractRows(mixed $payload): array
    {
        $rows = [];
        $this->collectRows($payload, $rows, 0);

        return $rows;
    }

    private function collectRows(mixed $node, array &$rows, int $depth): void
    {
        if ($depth > 12 || !is_array($node)) {
            return;
        }
        if ([] === $node) {
            return;
        }
        $isCandidate = !array_is_list($node) && $this->isAccountCandidate($node);

        // Collect children first; only add the parent if no child candidates were found,
        // to avoid duplicating a parent row that wraps its own child accounts.
        $countBefore = count($rows);
        foreach ($node as $value) {
            if (is_array($value)) {
                $this->collectRows($value, $rows, $depth + 1);
            }
        }
        $childrenFound = count($rows) > $countBefore;

        if ($isCandidate && !$childrenFound) {
            $rows[] = $node;
        }
    }

    private function isAccountCandidate(array $row): bool
    {
        $id = $this->firstNonEmpty(
            [
                $row['id'] ?? null,
                $row['ucid'] ?? null,
                $row['accountId'] ?? null,
                $row['account_id'] ?? null,
                $row['accountNumber'] ?? null,
                $row['number'] ?? null,
            ]
        );
        if ('' === $id) {
            return false;
        }
        if (isset($row['name']) || isset($row['title']) || isset($row['label'])) {
            return true;
        }
        if ('' !== $this->resolveCurrency($row)) {
            return true;
        }
        if (null !== $this->resolveAmount($row, ['balance', 'availableBalance', 'amount', 'moneyAmount', 'availableMoney'])) {
            return true;
        }

        return isset($row['type']) || isset($row['accountType']) || isset($row['status']);
    }

    private function normalizeAccount(array $row): ?array
    {
        $id = $this->firstNonEmpty(
            [
                $row['id'] ?? null,
                $row['ucid'] ?? null,
                $row['accountId'] ?? null,
                $row['account_id'] ?? null,
                $row['accountNumber'] ?? null,
                $row['number'] ?? null,
            ]
        );
        if ('' === $id) {
            return null;
        }

        $currency  = $this->resolveCurrency($row);
        $name      = $this->firstNonEmpty([$row['name'] ?? null, $row['title'] ?? null, $row['label'] ?? null], sprintf('TBank account %s', $id));
        $accountNo = $this->firstNonEmpty([$row['accountNumber'] ?? null, $row['number'] ?? null, $id]);
        $iban      = $this->firstNonEmpty([$row['iban'] ?? null, $row['IBAN'] ?? null]);
        $bban      = $this->firstNonEmpty([$row['bban'] ?? null, $row['BBAN'] ?? null, $accountNo]);
        $status    = $this->firstNonEmpty([$row['status'] ?? null, $row['state'] ?? null], 'active');
        $type      = strtolower($this->firstNonEmpty([$row['type'] ?? null, $row['accountType'] ?? null]));
        $isCard    = true === (bool)($row['is_card'] ?? false) || str_contains($type, 'card');
        $balance   = $this->resolveAmount($row, ['balance', 'amount', 'moneyAmount', 'totalAmount']);
        $available = $this->resolveAmount($row, ['availableBalance', 'available', 'availableMoney', 'freeAmount']);

        $syncIds = array_values(array_unique(array_filter([$id, $accountNo, $iban], static fn (string $value): bool => '' !== trim($value))));

        return [
            'id'               => $id,
            'name'             => $name,
            'institution_name' => 'TBank',
            'institution_logo' => '',
            'provider'         => 'tbank',
            'iban'             => $iban,
            'bban'             => $bban,
            'currency'         => $currency,
            'status'           => $status,
            'balance'          => $balance,
            'available'        => $available,
            'is_card'          => $isCard,
            'sync_ids'         => $syncIds,
            'extra'            => [
                'account_number' => $accountNo,
                'account_type'   => $type,
            ],
        ];
    }

    private function resolveCurrency(array $row): string
    {
        $direct = CurrencyCode::normalizeOrEmpty(
            $this->firstNonEmpty(
                [
                    $row['currency'] ?? null,
                    $row['currencyCode'] ?? null,
                    $row['currency_code'] ?? null,
                ]
            )
        );
        if ('' !== $direct) {
            return $direct;
        }

        foreach (['moneyAmount', 'availableMoney', 'amount', 'balance'] as $key) {
            if (!isset($row[$key]) || !is_array($row[$key])) {
                continue;
            }
            $nested = CurrencyCode::normalizeOrEmpty(
                $this->firstNonEmpty(
                    [
                        $row[$key]['currency'] ?? null,
                        $row[$key]['currencyCode'] ?? null,
                        $row[$key]['name'] ?? null,
                        $row[$key]['code'] ?? null,
                    ]
                )
            );
            if ('' !== $nested) {
                return $nested;
            }
        }

        return $this->searchCurrencyRecursive($row, 0);
    }

    private function searchCurrencyRecursive(mixed $node, int $depth): string
    {
        if ($depth > 6 || !is_array($node)) {
            return '';
        }
        foreach ($node as $key => $value) {
            $keyString = strtolower((string)$key);
            if (is_string($value) && (str_contains($keyString, 'currency') || str_contains($keyString, 'code'))) {
                $normalized = CurrencyCode::normalizeOrEmpty($value);
                if ('' !== $normalized) {
                    return $normalized;
                }
            }
            if (is_array($value)) {
                $nested = $this->searchCurrencyRecursive($value, $depth + 1);
                if ('' !== $nested) {
                    return $nested;
                }
            }
        }

        return '';
    }

    private function resolveAmount(array $row, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }
            $value = $row[$key];
            if (is_numeric($value)) {
                return (float)$value;
            }
            if (is_array($value)) {
                foreach (['value', 'amount', 'sum'] as $nestedKey) {
                    if (isset($value[$nestedKey]) && is_numeric($value[$nestedKey])) {
                        return (float)$value[$nestedKey];
                    }
                }
            }
        }

        return null;
    }

    // firstNonEmpty() is now provided by SessionJsonRequest parent class
}
