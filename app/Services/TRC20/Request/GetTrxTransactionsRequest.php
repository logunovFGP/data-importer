<?php

declare(strict_types=1);

namespace App\Services\TRC20\Request;

use App\Exceptions\ImporterHttpException;
use App\Services\TRC20\Response\GetTransactionsResponse;
use App\Services\Shared\Request\BearerJsonRequest;
use App\Services\TRC20\Support\TRC20AddressValidator;
use App\Services\TRC20\Support\TRC20Constants;
use App\Services\TRC20\Support\TronAddressCodec;
use Illuminate\Support\Facades\Log;

/**
 * Fetches native TRX transfer transactions from TronGrid.
 *
 * Uses the `/v1/accounts/{address}/transactions` endpoint (general transactions),
 * filtering for `TransferContract` type only (native TRX sends/receives).
 *
 * Key differences from TRC20 token endpoint:
 * - Addresses in response are hex-encoded (41-prefix), not Base58
 * - Amount is in `raw_data.contract[0].parameter.value.amount` (sun)
 * - Transaction types include TransferContract, TriggerSmartContract, etc.
 *   — only TransferContract is a native TRX transfer
 */
class GetTrxTransactionsRequest extends BearerJsonRequest
{
    use TRC20RequestTrait;

    private readonly int $pageSize;

    public function __construct(
        private readonly string $apiKey,
        private readonly array  $wallets,
        ?int                    $pageSize = null
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

        $this->setTimeOut((float)config('importer.connection.timeout'));

        $allNormalized = [];
        $lastCursor    = $cursor;

        foreach ($wallets as $wallet) {
            $endpoint = sprintf((string)config('trc20.trx_transactions_endpoint'), $wallet);
            $query    = $this->buildQuery($dateFrom, $dateTo, $lastCursor);

            Log::debug(sprintf('TRX: fetching native transactions for wallet %s from endpoint: %s', $wallet, $endpoint));

            $payload = $this->getJson($endpoint, $this->requestHeaders(), $query);

            if (isset($payload['success']) && false === $payload['success']) {
                $errorMsg = $payload['error'] ?? $payload['statusMessage'] ?? 'Unknown TronGrid error';
                Log::error(sprintf('TRX: TronGrid API error for wallet %s: %s', $wallet, $errorMsg));
                throw new ImporterHttpException(sprintf('TronGrid API error: %s', $errorMsg));
            }

            $rows = $payload['data'] ?? [];
            if (!is_array($rows)) {
                $rows = [];
            }

            Log::debug(sprintf('TRX: received %d rows for wallet %s.', count($rows), $wallet));

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $transaction = $this->normalizeTransaction($row, $wallets);
                if (null !== $transaction) {
                    $allNormalized[] = $transaction;
                }
            }

            $lastCursor = $this->extractFingerprint($payload);
        }

        if (0 === count($allNormalized)) {
            Log::info('TRX: 0 native TRX transactions matched after normalization.');
            return $this->emptyResponse();
        }

        usort($allNormalized, static fn(array $a, array $b): int => strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? '')));

        $response = new GetTransactionsResponse($allNormalized);
        $response->setNextCursor($lastCursor);
        $response->processData();

        return $response;
    }

    public function getPageSize(): int
    {
        return $this->pageSize;
    }

    private function normalizeTransaction(array $row, array $wallets): ?array
    {
        // Only process successful TransferContract (native TRX transfers)
        $contract = $row['raw_data']['contract'][0] ?? null;
        if (!is_array($contract) || 'TransferContract' !== ($contract['type'] ?? '')) {
            return null;
        }
        if ('SUCCESS' !== ($row['ret'][0]['contractRet'] ?? '')) {
            return null;
        }

        $value = $contract['parameter']['value'] ?? [];

        // Convert hex addresses to Base58
        $fromAddress = TronAddressCodec::hexToBase58((string)($value['owner_address'] ?? ''));
        $toAddress   = TronAddressCodec::hexToBase58((string)($value['to_address'] ?? ''));
        if ('' === $fromAddress || '' === $toAddress) {
            return null;
        }

        $isOutgoing = TRC20AddressValidator::walletInList($fromAddress, $wallets);
        $isIncoming = TRC20AddressValidator::walletInList($toAddress, $wallets);
        if (!$isOutgoing && !$isIncoming) {
            return null;
        }

        // Amount is in sun (1 TRX = 1,000,000 sun)
        $amountSun = (string)($value['amount'] ?? '0');
        $amountTrx = bcdiv($amountSun, bcpow('10', (string)TRC20Constants::TRX_DECIMALS), TRC20Constants::TRX_DECIMALS);
        if (0 === bccomp($amountTrx, '0', TRC20Constants::TRX_DECIMALS)) {
            return null;
        }

        $signedAmount = $isOutgoing ? '-' . $amountTrx : $amountTrx;

        // Date from block_timestamp (milliseconds)
        $blockTs = $row['block_timestamp'] ?? null;
        if (null === $blockTs) {
            return null;
        }
        $date = date('Y-m-d', (int)($blockTs / 1000));

        $txId = trim((string)($row['txID'] ?? ''));
        if ('' === $txId) {
            return null;
        }

        $accountId    = $isOutgoing ? $fromAddress : $toAddress;
        $counterparty = $isOutgoing ? $toAddress : $fromAddress;
        $description  = sprintf('TRX %s transfer %s', $isOutgoing ? 'outgoing' : 'incoming', $txId);

        $perTokenAccountId = sprintf('%s|%s', $accountId, TRC20Constants::CURRENCY_TRX);

        return [
            'id'             => $txId,
            'accountId'      => $perTokenAccountId,
            'amount'         => $signedAmount,
            'currency'       => TRC20Constants::CURRENCY_TRX,
            'date'           => $date,
            'merchant'       => $counterparty,
            'description'    => $description,
            'token_symbol'   => TRC20Constants::CURRENCY_TRX,
            'token_contract' => '',
            'from_address'   => $fromAddress,
            'to_address'     => $toAddress,
            'raw_token_row'  => $row,
        ];
    }

    // requestHeaders(), buildQuery(), extractFingerprint(), toMillisecondTimestamp()
    // provided by TRC20RequestTrait

    private function emptyResponse(): GetTransactionsResponse
    {
        $response = new GetTransactionsResponse([]);
        $response->processData();

        return $response;
    }
}
