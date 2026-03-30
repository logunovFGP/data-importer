<?php

declare(strict_types=1);

namespace App\Services\TRC20\Request;

use App\Exceptions\ImporterHttpException;
use App\Services\TRC20\Response\GetTransactionsResponse;
use App\Services\Shared\Request\BearerJsonRequest;
use App\Services\Shared\Support\CurrencyCode;
use App\Services\TRC20\Support\TRC20AddressValidator;
use App\Services\TRC20\Support\TRC20AmountParser;
use App\Services\TRC20\Support\TRC20Constants;
use App\Services\TRC20\Support\TRC20TokenFilter;
use Illuminate\Support\Facades\Log;

class GetTransactionsRequest extends BearerJsonRequest
{
    use TRC20RequestTrait;

    private readonly int $pageSize;

    public function __construct(
        private readonly string $apiKey,
        private readonly array $wallets,
        ?int $pageSize = null
    ) {
        parent::__construct((string)config('trc20.api_url'), $this->apiKey);
        $this->pageSize = $pageSize ?? (int)config('trc20.page_size', 200);
    }

    /**
     * @throws ImporterHttpException
     */
    public function get(?string $dateFrom = null, ?string $dateTo = null, ?string $cursor = null): GetTransactionsResponse
    {
        $wallets = TRC20AddressValidator::normalizeAndValidate($this->wallets);
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
                        'currency'       => TRC20Constants::CURRENCY_USDT,
                        'date'           => date('Y-m-d'),
                        'merchant'       => 'TRC20 Demo Recipient',
                        'description'    => 'TRC20 demo transaction',
                        'token_symbol'   => TRC20Constants::CURRENCY_USDT,
                        'from_address'   => $wallet,
                        'to_address'     => 'TGdemoRecipientAddress',
                    ],
                ]
            );
            $response->setNextCursor($cursor);
            $response->processData();

            return $response;
        }

        // TronGrid requires per-wallet queries (address in path)
        $allNormalized = [];
        $lastCursor    = $cursor;

        foreach ($wallets as $wallet) {
            // Reset cursor at the start of each wallet — TronGrid fingerprints are per-address.
            // Without this, wallet B would receive wallet A's cursor, producing wrong/empty results.
            $lastCursor = null;

            $endpoint = sprintf((string)config('trc20.transactions_endpoint'), $wallet);
            $query    = $this->buildQuery($dateFrom, $dateTo, $lastCursor);

            Log::debug(sprintf('TRC20: fetching transactions for wallet %s from TronGrid endpoint: %s', $wallet, $endpoint));

            $payload  = $this->getJson($endpoint, $this->requestHeaders(), $query);

            if (isset($payload['success']) && false === $payload['success']) {
                $errorMsg = $payload['error'] ?? $payload['statusMessage'] ?? 'Unknown TronGrid error';
                Log::error(sprintf('TRC20: TronGrid API error for wallet %s: %s', $wallet, $errorMsg));
                throw new ImporterHttpException(sprintf('TronGrid API error: %s', $errorMsg));
            }

            $rows = $payload['data'] ?? [];
            if (!is_array($rows)) {
                Log::warning(sprintf('TRC20: unexpected response format for wallet %s — "data" is not an array.', $wallet));
                $rows = [];
            }

            Log::debug(sprintf('TRC20: received %d rows for wallet %s.', count($rows), $wallet));

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $transaction = $this->normalizeTransaction($row, $wallets);
                if (null !== $transaction) {
                    $allNormalized[] = $transaction;
                }
            }

            // Extract fingerprint for pagination
            $lastCursor = $this->extractFingerprint($payload);
        }

        if (0 === count($allNormalized)) {
            Log::info('TRC20: 0 transactions matched after normalization.');
            return $this->emptyResponse();
        }

        usort($allNormalized, static function (array $left, array $right): int {
            return strcmp((string)($right['date'] ?? ''), (string)($left['date'] ?? ''));
        });

        $response = new GetTransactionsResponse($allNormalized);
        $response->setNextCursor($lastCursor);
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
            $currency = CurrencyCode::normalizeOrEmpty((string)($row['currency'] ?? $row['token_symbol'] ?? TRC20Constants::CURRENCY_USDT));
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

    // requestHeaders(), buildQuery(), extractFingerprint(), toMillisecondTimestamp()
    // provided by TRC20RequestTrait

    private function normalizeTransaction(array $row, array $wallets): ?array
    {
        if (!TRC20TokenFilter::isSupported($row)) {
            return null;
        }

        $tokenSymbol = TRC20TokenFilter::extractSymbol($row);
        if ('' === $tokenSymbol) {
            $tokenSymbol = TRC20Constants::CURRENCY_USDT;
        }

        $fromAddress = trim((string)($row['from'] ?? ''));
        $toAddress   = trim((string)($row['to'] ?? ''));

        $isOutgoing = '' !== $fromAddress && TRC20AddressValidator::walletInList($fromAddress, $wallets);
        $isIncoming = '' !== $toAddress && TRC20AddressValidator::walletInList($toAddress, $wallets);

        if (!$isOutgoing && !$isIncoming) {
            return null;
        }

        $accountId = $isOutgoing ? $fromAddress : $toAddress;
        if ('' === $accountId) {
            return null;
        }

        $amountStr = TRC20AmountParser::parse($row);
        if (null === $amountStr || 0 === bccomp(ltrim($amountStr, '-'), '0', 12)) {
            return null;
        }
        $absAmountStr = ltrim($amountStr, '-');
        if ($isOutgoing) {
            $amountStr = '-' . $absAmountStr;
        }

        $date = $this->normalizeDate($row);
        if (null === $date) {
            return null;
        }

        $txId = trim((string)($row['transaction_id'] ?? ''));
        if ('' === $txId) {
            try {
                $txId = hash(
                    'sha256',
                    json_encode([$accountId, $fromAddress, $toAddress, $amountStr, $date], JSON_THROW_ON_ERROR)
                );
            } catch (\Throwable) {
                return null;
            }
        }

        $counterparty = $isOutgoing ? $toAddress : $fromAddress;
        $description  = sprintf('%s transfer %s', $tokenSymbol, $txId);

        // accountId includes token symbol for per-token account routing
        $perTokenAccountId = sprintf('%s|%s', $accountId, $tokenSymbol);

        return [
            'id'             => $txId,
            'accountId'      => $perTokenAccountId,
            'amount'         => $amountStr,
            'currency'       => $tokenSymbol,
            'date'           => $date,
            'merchant'       => $counterparty,
            'description'    => $description,
            'token_symbol'   => $tokenSymbol,
            'token_contract' => TRC20TokenFilter::extractContract($row),
            'from_address'   => $fromAddress,
            'to_address'     => $toAddress,
            'raw_token_row'  => $row,
        ];
    }

    public function getPageSize(): int
    {
        return $this->pageSize;
    }

    private function normalizeDate(array $row): ?string
    {
        $rawDate = $row['block_timestamp'] ?? null;
        if (!is_numeric($rawDate)) {
            return null;
        }

        $timestamp = (int)$rawDate;
        if ($timestamp > TRC20Constants::UNIX_MS_THRESHOLD) {
            $timestamp = intdiv($timestamp, 1000);
        }
        if ($timestamp < 0) {
            return null;
        }

        return date('Y-m-d', $timestamp);
    }

    private function emptyResponse(): GetTransactionsResponse
    {
        return new GetTransactionsResponse([]);
    }

}
