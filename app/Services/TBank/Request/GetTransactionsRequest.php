<?php

declare(strict_types=1);

namespace App\Services\TBank\Request;

use App\Exceptions\ImporterHttpException;
use App\Services\LunchFlow\Response\GetTransactionsResponse;
use App\Services\Shared\Support\CurrencyCode;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class GetTransactionsRequest extends SessionJsonRequest
{
    public function __construct(
        string $sessionId,
        string $cookieHeader,
        private readonly string $accountId
    ) {
        parent::__construct((string)config('tbank.api_url'), $sessionId, $cookieHeader);
    }

    /**
     * @throws ImporterHttpException
     */
    public function get(?string $dateFrom = null, ?string $dateTo = null): GetTransactionsResponse
    {
        if (true === config('importer.fake_data')) {
            $response = new GetTransactionsResponse(
                [
                    [
                        'id'          => sprintf('tbank-%s-1', $this->accountId),
                        'accountId'   => $this->accountId,
                        'amount'      => '-950.00',
                        'currency'    => 'RUB',
                        'date'        => '2025-10-16',
                        'merchant'    => 'TBank Demo Merchant',
                        'description' => 'TBank demo transaction',
                    ],
                ]
            );
            $response->processData();

            return $response;
        }

        $query      = $this->buildBaseQuery(
            [
                'start'   => $this->toEpochMillis($dateFrom, false),
                'end'     => $this->toEpochMillis($dateTo, true),
                'account' => $this->accountId,
            ]
        );
        $decoded    = $this->getJson((string)config('tbank.transactions_endpoint'), $query);
        $rows       = $this->extractRows($decoded['payload'] ?? $decoded);
        $normalized = [];
        foreach ($rows as $row) {
            $transaction = $this->normalizeTransaction($row);
            if (null === $transaction) {
                continue;
            }
            if ($this->isExplicitlyForAnotherAccount($row, $transaction['accountId'])) {
                continue;
            }
            $normalized[] = $transaction;
        }
        Log::debug(sprintf('TBank transaction request for account %s normalized %d transaction(s).', $this->accountId, count($normalized)));

        $response = new GetTransactionsResponse($normalized);
        $response->processData();

        return $response;
    }

    /**
     * @return array<string>
     * @throws ImporterHttpException
     */
    public function sampleAccountCurrencies(?string $dateFrom = null, ?string $dateTo = null, int $sampleSize = 10): array
    {
        $response   = $this->get($dateFrom, $dateTo);
        $currencies = [];
        $limit      = max(1, $sampleSize);
        foreach ($response as $item) {
            $currency = CurrencyCode::normalizeOrEmpty((string)($item->currency ?? ''));
            if ('' === $currency) {
                continue;
            }
            $currencies[] = $currency;
            if (count($currencies) >= $limit) {
                break;
            }
        }

        return $currencies;
    }

    /**
     * @throws ImporterHttpException
     */
    public function detectAccountCurrency(?string $dateFrom = null, ?string $dateTo = null, int $sampleSize = 10): string
    {
        return CurrencyCode::selectDominant($this->sampleAccountCurrencies($dateFrom, $dateTo, $sampleSize));
    }

    private function extractRows(mixed $payload): array
    {
        if (is_array($payload) && array_is_list($payload)) {
            return array_values(array_filter($payload, static fn (mixed $row): bool => is_array($row)));
        }
        if (is_array($payload) && isset($payload['operations']) && is_array($payload['operations'])) {
            return array_values(array_filter($payload['operations'], static fn (mixed $row): bool => is_array($row)));
        }
        if (is_array($payload) && isset($payload['items']) && is_array($payload['items'])) {
            return array_values(array_filter($payload['items'], static fn (mixed $row): bool => is_array($row)));
        }

        $rows = [];
        $this->collectTransactionRows($payload, $rows, 0);

        return $rows;
    }

    private function collectTransactionRows(mixed $node, array &$rows, int $depth): void
    {
        if ($depth > 12 || !is_array($node)) {
            return;
        }
        if ([] === $node) {
            return;
        }
        $isCandidate = !array_is_list($node) && $this->isTransactionCandidate($node);

        // Collect children first; only add the parent if no child candidates were found,
        // to avoid duplicating a parent row that wraps its own child transactions.
        $countBefore = count($rows);
        foreach ($node as $value) {
            if (is_array($value)) {
                $this->collectTransactionRows($value, $rows, $depth + 1);
            }
        }
        $childrenFound = count($rows) > $countBefore;

        if ($isCandidate && !$childrenFound) {
            $rows[] = $node;
        }
    }

    private function isTransactionCandidate(array $row): bool
    {
        $amount = $this->extractAmount($row);
        $date   = $this->extractDate($row);
        $id     = $this->firstNonEmpty(
            [
                $row['id'] ?? null,
                $row['operationId'] ?? null,
                $row['externalId'] ?? null,
                $row['uid'] ?? null,
            ]
        );

        return '' !== $id || null !== $amount || '' !== $date;
    }

    private function normalizeTransaction(array $row): ?array
    {
        $amount = $this->extractAmount($row);
        if (null === $amount) {
            return null;
        }

        $accountId = $this->extractAccountId($row);
        if ('' === $accountId) {
            $accountId = $this->accountId;
        }

        $currency   = $this->extractCurrency($row);
        if ('' === $currency) {
            $currency = 'RUB';
        }
        $date       = $this->extractDate($row);
        if ('' === $date) {
            $date = date('Y-m-d');
        }
        $description = trim(
            $this->firstNonEmpty(
                [
                    $row['description'] ?? null,
                    $row['title'] ?? null,
                    $row['comment'] ?? null,
                    $row['details'] ?? null,
                    $row['paymentPurpose'] ?? null,
                ]
            )
        );
        $merchant   = trim(
            $this->firstNonEmpty(
                [
                    $row['merchant'] ?? null,
                    $row['merchantName'] ?? null,
                    $row['counterparty'] ?? null,
                    $row['payee'] ?? null,
                ]
            )
        );

        $externalId = trim(
            $this->firstNonEmpty(
                [
                    $row['id'] ?? null,
                    $row['operationId'] ?? null,
                    $row['externalId'] ?? null,
                    $row['uid'] ?? null,
                ]
            )
        );
        if ('' === $externalId) {
            $externalId = md5((string)json_encode([$accountId, $amount, $currency, $date, $description, $merchant]));
        }

        return [
            'id'          => $externalId,
            'accountId'   => $accountId,
            'amount'      => (string)$amount,
            'currency'    => $currency,
            'date'        => $date,
            'merchant'    => $merchant,
            'description' => $description,
        ];
    }

    private function isExplicitlyForAnotherAccount(array $row, string $normalizedAccountId): bool
    {
        $candidates = array_values(array_unique(array_filter(
            [
                trim((string)($row['account'] ?? '')),
                trim((string)($row['accountId'] ?? '')),
                trim((string)($row['account_id'] ?? '')),
                trim((string)($row['ucid'] ?? '')),
                trim((string)($row['sourceAccount'] ?? '')),
                trim((string)($row['destinationAccount'] ?? '')),
            ],
            static fn (string $value): bool => '' !== $value
        )));
        if ([] === $candidates) {
            return false;
        }
        if (in_array($this->accountId, $candidates, true)) {
            return false;
        }

        return !in_array($normalizedAccountId, $candidates, true);
    }

    private function extractAmount(array $row): ?float
    {
        $value = null;
        foreach (['amount', 'sum', 'value'] as $key) {
            if (isset($row[$key]) && is_numeric($row[$key])) {
                $value = (float)$row[$key];
                break;
            }
            if (isset($row[$key]) && is_array($row[$key])) {
                foreach (['value', 'amount', 'sum'] as $nestedKey) {
                    if (isset($row[$key][$nestedKey]) && is_numeric($row[$key][$nestedKey])) {
                        $value = (float)$row[$key][$nestedKey];
                        break 2;
                    }
                }
            }
        }
        if (null === $value) {
            foreach (['moneyAmount', 'operationAmount', 'paymentAmount'] as $key) {
                if (!isset($row[$key]) || !is_array($row[$key])) {
                    continue;
                }
                foreach (['value', 'amount', 'sum'] as $nestedKey) {
                    if (isset($row[$key][$nestedKey]) && is_numeric($row[$key][$nestedKey])) {
                        $value = (float)$row[$key][$nestedKey];
                        break 2;
                    }
                }
            }
        }
        if (null === $value) {
            return null;
        }

        $isIncome = $this->normalizeBool($row['isIncome'] ?? null);
        $isDebit  = $this->normalizeBool($row['isDebit'] ?? null);
        $sign     = strtolower($this->firstNonEmpty([$row['sign'] ?? null, $row['operationType'] ?? null, $row['type'] ?? null]));
        if (true === $isIncome) {
            return abs($value);
        }
        if (true === $isDebit || str_contains($sign, 'debit') || str_contains($sign, 'withdraw') || str_contains($sign, 'expense') || str_contains($sign, 'payment')) {
            return -1 * abs($value);
        }
        if (str_contains($sign, 'credit') || str_contains($sign, 'income') || str_contains($sign, 'deposit')) {
            return abs($value);
        }
        // No direction indicator found — return the raw value as-is.
        // Previously this forced positive amounts negative, turning deposits into expenses.
        return (float)$value;
    }

    private function extractCurrency(array $row): string
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

        foreach (['amount', 'moneyAmount', 'operationAmount', 'paymentAmount'] as $key) {
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

        return '';
    }

    private function extractDate(array $row): string
    {
        $value = $this->firstNonEmpty(
            [
                $row['date'] ?? null,
                $row['operationDate'] ?? null,
                $row['operationTime'] ?? null,
                $row['createdAt'] ?? null,
                $row['created'] ?? null,
                $row['timestamp'] ?? null,
            ]
        );
        if ('' === $value) {
            return '';
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return '';
        }
    }

    private function extractAccountId(array $row): string
    {
        return $this->firstNonEmpty(
            [
                $row['account'] ?? null,
                $row['accountId'] ?? null,
                $row['account_id'] ?? null,
                $row['ucid'] ?? null,
                $row['sourceAccount'] ?? null,
                $row['destinationAccount'] ?? null,
                $row['cardId'] ?? null,
            ]
        );
    }

    private function toEpochMillis(?string $date, bool $endOfDay): int
    {
        if ('' === trim((string)$date)) {
            return $endOfDay
                ? (int)(microtime(true) * 1000)
                : Carbon::now()->subDays(30)->startOfDay()->valueOf();
        }

        try {
            $carbon = Carbon::parse($date);
        } catch (\Throwable) {
            $carbon = Carbon::now();
        }
        if ($endOfDay) {
            $carbon = $carbon->endOfDay();
        } else {
            $carbon = $carbon->startOfDay();
        }

        return $carbon->valueOf();
    }

    private function normalizeBool(mixed $value): ?bool
    {
        if (true === $value || false === $value) {
            return $value;
        }
        $string = strtolower(trim((string)$value));
        if (in_array($string, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($string, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return null;
    }

    // firstNonEmpty() is now provided by SessionJsonRequest parent class
}
