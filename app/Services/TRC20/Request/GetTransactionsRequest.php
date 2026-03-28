<?php

declare(strict_types=1);

namespace App\Services\TRC20\Request;

use App\Exceptions\ImporterHttpException;
use App\Services\TRC20\Response\GetTransactionsResponse;
use App\Services\Shared\Request\BearerJsonRequest;
use App\Services\Shared\Support\CurrencyCode;
use App\Services\TRC20\Support\TRC20AddressValidator;

class GetTransactionsRequest extends BearerJsonRequest
{
    private const string CURRENCY = 'USDT';
    private readonly int $pageSize;

    public function __construct(
        private readonly string $apiKey,
        private readonly array $wallets,
        ?int $pageSize = null
    ) {
        parent::__construct((string)config('trc20.api_url'), $this->apiKey);
        $this->pageSize = $pageSize ?? (int)config('trc20.page_size', 100);
    }

    /**
     * @throws ImporterHttpException
     */
    public function get(?string $dateFrom = null, ?string $dateTo = null, ?string $cursor = null): GetTransactionsResponse
    {
        $wallets = $this->normalizeWallets($this->wallets);
        if (0 === count($wallets)) {
            return $this->emptyResponse();
        }
        if (0 >= $this->pageSize) {
            return $this->emptyResponse();
        }

        $this->setTimeOut((float)config('importer.connection.timeout'));

        if (true === config('importer.fake_data')) {
            $wallet = $wallets[0] ?? 'TTEST0000000000000000000000000000000';
            $response = new GetTransactionsResponse(
                [
                    [
                        'id'             => sprintf('trc20-%s-%s', $wallet, date('Ymd')),
                        'accountId'      => $wallet,
                        'amount'         => '123.45',
                        'currency'       => self::CURRENCY,
                        'date'           => date('Y-m-d'),
                        'merchant'       => 'TRC20 Demo Recipient',
                        'description'    => 'TRC20 demo transaction',
                        'token_symbol'   => self::CURRENCY,
                        'from_address'   => $wallet,
                        'to_address'     => 'TGdemoRecipientAddress',
                    ],
                ]
            );
            $response->setNextCursor($cursor);
            $response->processData();

            return $response;
        }

        $payload   = $this->getJson(
            (string)config('trc20.transactions_endpoint'),
            $this->requestHeaders(),
            $this->buildQuery($wallets, $dateFrom, $dateTo, $cursor)
        );
        $rows      = $this->extractRows($payload);
        if (0 === count($rows)) {
            return $this->emptyResponse();
        }

        $nextCursor = $this->extractCursor($payload);
        $normalized = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $transaction = $this->normalizeTransaction($row, $wallets);
            if (null === $transaction) {
                continue;
            }

            $normalized[] = $transaction;
        }

        usort($normalized, static function (array $left, array $right): int {
            return strcmp((string)($right['date'] ?? ''), (string)($left['date'] ?? ''));
        });

        $response = new GetTransactionsResponse($normalized);
        $response->setNextCursor($nextCursor);
        $response->processData();

        return $response;
    }

    /**
     * @return array<string>
     * @throws ImporterHttpException
     */
    public function sampleAccountCurrencies(?string $dateFrom = null, ?string $dateTo = null, int $sampleSize = 10): array
    {
        $limit      = max(1, $sampleSize);
        $response   = $this->get($dateFrom, $dateTo, null);
        $currencies = [];
        foreach ($response->getRawData() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $currency = CurrencyCode::normalizeOrEmpty((string)($row['currency'] ?? $row['token_symbol'] ?? self::CURRENCY));
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
        return $this->selectDominantCurrency($this->sampleAccountCurrencies($dateFrom, $dateTo, $sampleSize));
    }

    private function requestHeaders(): array
    {
        return [
            'TRON-PRO-API-KEY' => $this->apiKey,
        ];
    }

    private function buildQuery(array $wallets, ?string $dateFrom, ?string $dateTo, ?string $cursor): array
    {
        $query = [
            'address' => implode(',', $wallets),
            'token'   => self::CURRENCY,
            'limit'   => $this->pageSize,
        ];

        $fromTimestamp = $this->toUnixTimestamp($dateFrom);
        if (null !== $fromTimestamp) {
            $query['from']           = (string)$dateFrom;
            $query['from_timestamp'] = (string)$fromTimestamp;
            $query['start']          = (string)$fromTimestamp;
        }

        $toTimestamp = $this->toUnixTimestamp($dateTo);
        if (null !== $toTimestamp) {
            $query['to']             = (string)$dateTo;
            $query['to_timestamp']   = (string)$toTimestamp;
            $query['end']            = (string)$toTimestamp;
        }

        if (null !== $cursor && '' !== trim($cursor)) {
            $query['cursor'] = $cursor;
        }

        return $query;
    }

    private function extractRows(array $payload): array
    {
        if (isset($payload['data']) && is_array($payload['data']) && array_is_list($payload['data'])) {
            return $payload['data'];
        }
        if (isset($payload['data']) && is_array($payload['data']) && isset($payload['data']['transfers']) && is_array($payload['data']['transfers'])) {
            return $payload['data']['transfers'];
        }
        if (isset($payload['token_transfers']) && is_array($payload['token_transfers']) && array_is_list($payload['token_transfers'])) {
            return $payload['token_transfers'];
        }
        if (isset($payload['transactions']) && is_array($payload['transactions'])) {
            return array_is_list($payload['transactions']) ? $payload['transactions'] : [];
        }
        if (isset($payload['transfers']) && is_array($payload['transfers']) && array_is_list($payload['transfers'])) {
            return $payload['transfers'];
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
        if (isset($payload['meta']['next_cursor']) && '' !== trim((string)$payload['meta']['next_cursor'])) {
            return (string)$payload['meta']['next_cursor'];
        }
        if (isset($payload['page_info']['next']) && '' !== trim((string)$payload['page_info']['next'])) {
            return (string)$payload['page_info']['next'];
        }
        return null;
    }

    private function normalizeTransaction(array $row, array $wallets): ?array
    {
        if (!$this->isTargetToken($row)) {
            return null;
        }

        $fromAddress = $this->normalizeWallet((string)($row['from_address'] ?? $row['ownerAddress'] ?? $row['fromAddress'] ?? $row['from'] ?? ''));
        $toAddress   = $this->normalizeWallet((string)($row['to_address'] ?? $row['toAddress'] ?? $row['to'] ?? ''));

        $isOutgoing = '' !== $fromAddress && TRC20AddressValidator::walletInList($fromAddress, $wallets);
        $isIncoming = '' !== $toAddress && TRC20AddressValidator::walletInList($toAddress, $wallets);

        if (!$isOutgoing && !$isIncoming) {
            return null;
        }

        $accountId = $isOutgoing ? $fromAddress : $toAddress;
        if ('' === $accountId) {
            return null;
        }

        $amount = $this->normalizeAmount($row);
        if (null === $amount) {
            return null;
        }
        if (0.0 === $amount) {
            return null;
        }
        if ($isOutgoing) {
            $amount = -1 * abs($amount);
        }

        $date = $this->normalizeDate($row);
        if (null === $date) {
            return null;
        }

        $txId = $this->extractTransactionId($row);
        if ('' === $txId) {
            try {
                $txId = hash(
                    'sha256',
                    json_encode([
                        $accountId,
                        $fromAddress,
                        $toAddress,
                        $amount,
                        $date,
                    ], JSON_THROW_ON_ERROR)
                );
            } catch (\Throwable $e) {
                return null;
            }
        }

        $counterparty = $isOutgoing ? $toAddress : $fromAddress;
        $memo        = trim((string)($row['memo'] ?? $row['note'] ?? $row['txInfo'] ?? $row['contractData'] ?? ''));
        $description = trim((string)($row['data'] ?? $memo));
        if ('' === $description && '' !== $txId) {
            $description = sprintf('TRC20 transfer %s', $txId);
        }

        return [
            'id'             => $txId,
            'accountId'      => $accountId,
            'amount'         => (string)$amount,
            'currency'       => self::CURRENCY,
            'date'           => $date,
            'merchant'       => $counterparty,
            'description'    => $description,
            'token_symbol'   => self::CURRENCY,
            'token_contract' => $this->extractTokenContract($row),
            'from_address'   => $fromAddress,
            'to_address'     => $toAddress,
            'raw_token_row'  => $row,
        ];
    }

    private function extractTransactionId(array $row): string
    {
        return trim((string)($row['txID'] ?? $row['tx_id'] ?? $row['transaction_id'] ?? $row['hash'] ?? $row['id'] ?? ''));
    }

    private function isTargetToken(array $row): bool
    {
        $symbol = strtoupper(trim((string)($row['token'] ?? $row['symbol'] ?? $row['tokenName'] ?? $row['tokenInfo']['symbol'] ?? '')));
        if ('' !== $symbol && $symbol !== self::CURRENCY) {
            return false;
        }

        $contract = strtolower(trim($this->extractTokenContract($row)));
        if ('' === $symbol && '' === $contract) {
            return true;
        }

        $configuredContract = strtolower(trim((string)config('trc20.usdt_contract_address', '')));
        if ('' === $configuredContract) {
            return true;
        }

        if ('' === $contract) {
            return false;
        }

        return $contract === $configuredContract;
    }

    private function normalizeAmount(array $row): ?float
    {
        $value    = (string)($row['amount'] ?? $row['value'] ?? $row['tokenInfo']['amount'] ?? $row['quant'] ?? '');
        $decimals = (int)($row['decimals'] ?? $row['tokenInfo']['decimals'] ?? 0);
        if ('' === trim($value)) {
            return null;
        }

        if (is_numeric($value)) {
            $amount = (float)$value;
            if ($decimals > 0 && str_contains($value, '.') === false) {
                $amount = $amount / (10 ** $decimals);
            }
            return $amount;
        }

        return null;
    }

    private function normalizeDate(array $row): ?string
    {
        $rawDate = $row['block_ts'] ?? $row['block_timestamp'] ?? $row['timestamp'] ?? $row['time'] ?? $row['created_at'] ?? null;
        if (is_numeric($rawDate)) {
            $timestamp = (int)$rawDate;
            if ($timestamp > 9999999999) {
                $timestamp = intdiv($timestamp, 1000);
            }
            if ($timestamp < 0) {
                return null;
            }

            return date('Y-m-d', $timestamp);
        }

        if (is_string($rawDate) && strlen($rawDate) > 0) {
            $ts = strtotime($rawDate);
            if (false !== $ts) {
                return date('Y-m-d', $ts);
            }
            $normalized = substr(trim($rawDate), 0, 10);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $normalized) === 1) {
                return $normalized;
            }
        }

        return null;
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
            if (!in_array($value, $normalized, true)) {
                $normalized[] = $value;
            }
        }

        if (0 !== count($invalid)) {
            $invalid = array_values(array_unique(array_filter($invalid, static fn(string $wallet): bool => '' !== $wallet)));
            if (0 !== count($invalid)) {
                throw new ImporterHttpException(sprintf('Invalid TRC20 wallet format(s): %s', implode(', ', $invalid)));
            }
        }

        return $normalized;
    }

    private function normalizeWallet(string $wallet): string
    {
        return trim($wallet);
    }

    public function getPageSize(): int
    {
        return $this->pageSize;
    }

    private function extractTokenContract(array $row): string
    {
        return (string)($row['tokenInfo']['address'] ?? $row['tokenInfo']['tokenId'] ?? $row['token_id'] ?? $row['contract_address'] ?? '');
    }

    private function toUnixTimestamp(?string $value): ?int
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }

        $timestamp = strtotime($value);
        if (false === $timestamp) {
            return null;
        }

        return max(0, $timestamp);
    }

    private function emptyResponse(): GetTransactionsResponse
    {
        return new GetTransactionsResponse([]);
    }

    private function selectDominantCurrency(array $currencies): string
    {
        if ([] === $currencies) {
            return '';
        }
        $counter = [];
        foreach ($currencies as $currency) {
            $normalized = CurrencyCode::normalizeOrEmpty((string)$currency);
            if ('' === $normalized) {
                continue;
            }
            if (!array_key_exists($normalized, $counter)) {
                $counter[$normalized] = 0;
            }
            $counter[$normalized]++;
        }
        if ([] === $counter) {
            return '';
        }
        arsort($counter);
        $best = array_key_first($counter);

        return is_string($best) ? $best : '';
    }
}
