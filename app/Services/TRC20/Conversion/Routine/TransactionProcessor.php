<?php

declare(strict_types=1);

namespace App\Services\TRC20\Conversion\Routine;

use App\Exceptions\ImporterErrorException;
use App\Exceptions\ImporterHttpException;
use App\Models\ImportJob;
use App\Repository\ImportJob\ImportJobRepository;
use App\Services\Shared\Authentication\SecretManager as SharedSecretManager;
use App\Services\Shared\Conversion\ConversionStatus;
use App\Services\Shared\Conversion\CreatesAccounts;
use App\Services\Shared\Conversion\TransactionProcessorHelpers;
use App\Services\Shared\Model\ImportServiceAccount;
use App\Services\Shared\Preflight\ProviderCurrencyPreflightService;
use App\Services\Shared\SyncState\SyncStateManager;
use App\Services\TRC20\Authentication\SecretManager;
use App\Services\TRC20\Request\GetTransactionsRequest;
use App\Services\TRC20\Request\GetTrxTransactionsRequest;
use App\Services\TRC20\Request\GetWalletsRequest;
use App\Services\TRC20\Support\TRC20AddressValidator;
use App\Services\TRC20\Support\TRC20AmountParser;
use App\Services\TRC20\Support\TRC20Constants;
use App\Services\TRC20\Support\TRC20TokenFilter;
use App\Services\LunchFlow\Model\Transaction;
use App\Services\TRC20\Response\GetTransactionsResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class TransactionProcessor
{
    use CreatesAccounts;
    use TransactionProcessorHelpers;

    private const string DATE_TIME_FORMAT = 'Y-m-d H:i:s';

    private ImportJob $importJob;
    private array $accounts;
    private ?Carbon $notAfter = null;
    private ?Carbon $notBefore = null;
    private ImportJobRepository $repository;
    private SyncStateManager $syncStateManager;
    private string $contextFingerprint = '';
    private array $seenTransactionIds = [];

    /**
     * @throws ImporterErrorException
     */
    public function download(): array
    {
        $this->notBefore         = null;
        $this->notAfter          = null;
        $this->accounts          = [];
        $this->seenTransactionIds = [];
        $this->repository        = new ImportJobRepository();
        $this->syncStateManager  = new SyncStateManager();
        $this->contextFingerprint = $this->buildContextFingerprint();

        $configuration = $this->importJob->getConfiguration();
        if ('' !== $configuration->getDateNotBefore()) {
            $this->notBefore = new Carbon($configuration->getDateNotBefore());
        }
        if ('' !== $configuration->getDateNotAfter()) {
            $this->notAfter = new Carbon($configuration->getDateNotAfter());
        }

        $accounts = $configuration->getAccounts();
        $return   = [];
        $this->importJob->conversionStatus->setPullProgress(count($accounts), 0, ConversionStatus::PULL_STEP_PENDING);
        try {
            $this->existingServiceAccounts = $this->loadServiceAccounts($configuration);
            $this->importJob->setServiceAccounts($this->existingServiceAccounts);
        } catch (ImporterHttpException $e) {
            throw new ImporterErrorException(sprintf('Could not fetch TRC20 service accounts: %s', $e->getMessage()), 0, $e);
        }

        $preflight = app(ProviderCurrencyPreflightService::class)->evaluate($this->importJob);
        if (($preflight['changed'] ?? false) === true) {
            $configuration = $this->importJob->getConfiguration();
            $accounts      = $configuration?->getAccounts() ?? $accounts;
            $this->importJob->conversionStatus->setPullProgress(count($accounts), 0, ConversionStatus::PULL_STEP_PENDING);
            $this->saveConversionStatus();
        }
        if (($preflight['has_blocking_errors'] ?? false) === true) {
            foreach (($preflight['errors'] ?? []) as $error) {
                $this->importJob->conversionStatus->addError(0, (string)$error);
            }
            $this->saveConversionStatus();

            throw new ImporterErrorException('TRC-20 preflight currency validation failed. Resolve account currency mismatches and retry import.');
        }

        // Deduplicate wallets: multiple accounts (e.g., wallet|TRX, wallet|USDT) share the same raw wallet
        $walletAccountMap = [];
        foreach ($accounts as $importServiceAccountId => $fireflyIIIAccountId) {
            $compositeId = $this->normalizeWallet((string)$importServiceAccountId);
            if ('' === $compositeId) {
                continue;
            }

            // Extract raw wallet from composite "wallet|SYMBOL" ID
            $rawWallet = str_contains($compositeId, '|') ? explode('|', $compositeId, 2)[0] : $compositeId;

            if (0 === (int)$fireflyIIIAccountId) {
                $createdAccount = $this->createOrFindExistingAccount($compositeId);
                $updated        = $configuration->getAccounts();
                $updated[$compositeId] = $createdAccount->id;
                $configuration->setAccounts($updated);
                $this->accounts[$compositeId] = $createdAccount->id;
            } else {
                $this->accounts[$compositeId] = (int)$fireflyIIIAccountId;
            }

            $walletAccountMap[$rawWallet][] = $compositeId;
        }

        // Fetch transactions once per raw wallet (not per token account)
        foreach ($walletAccountMap as $rawWallet => $compositeIds) {
            $apiKey  = SecretManager::getApiKey($configuration);
            $request = new GetTransactionsRequest($apiKey, [$rawWallet]);
            $request->setTimeOut((float)config('importer.connection.timeout'));
            $shortWallet = substr($rawWallet, 0, 8) . '...' . substr($rawWallet, -4);
            $this->importJob->conversionStatus->addActivity(sprintf('Wallet %s: Starting transaction fetch from TronGrid...', $shortWallet));
            $this->importJob->conversionStatus->setPullChecklistItem(
                $rawWallet,
                ConversionStatus::PULL_STEP_RUNNING,
                'Fetching transactions...'
            );
            $this->saveConversionStatus();

            try {
                $trc20DateFrom = $this->notBefore?->format('Y-m-d');
                if (null === $trc20DateFrom && $configuration->isIncrementalSyncEnabled()) {
                    $trc20DateFrom = $this->resolveIncrementalDateFromCursor($rawWallet);
                }
                $transactions = $this->downloadWalletTransactions(
                    $request,
                    $rawWallet,
                    $trc20DateFrom,
                    $this->notAfter?->format('Y-m-d')
                );
            } catch (ImporterHttpException $e) {
                $this->importJob->conversionStatus->addWarning(0, $e->getMessage());
                $this->importJob->conversionStatus->setPullChecklistItem(
                    $rawWallet,
                    ConversionStatus::PULL_STEP_ERROR,
                    sprintf('Could not fetch transactions from TRC20: %s', $e->getMessage())
                );
                // No transactions for this wallet — nothing to add to $return
                $this->importJob->conversionStatus->incrementPullProgress();
                $this->saveConversionStatus();

                continue;
            }

            // Group transactions by their composite accountId (wallet|SYMBOL)
            // so GenerateTransactions can look up $this->accounts[$accountId] correctly.
            foreach ($transactions as $tx) {
                $txAccountId = $tx->account ?? $rawWallet;
                $return[$txAccountId] ??= [];
                $return[$txAccountId][] = $tx;
            }
            $this->importJob->conversionStatus->addActivity(sprintf('Wallet %s: Fetched %d transaction(s)', $shortWallet, count($transactions)));
            $latestDate = $this->resolveLatestTransactionDate($transactions);
            if (null !== $latestDate) {
                $this->importJob->conversionStatus->addPullCursorCandidate($rawWallet, $latestDate);
            }
            $this->importJob->conversionStatus->setPullChecklistItem(
                $rawWallet,
                ConversionStatus::PULL_STEP_DONE,
                sprintf('Fetched %d transaction(s)', count($transactions))
            );
            $this->importJob->conversionStatus->incrementPullProgress();
            $this->saveConversionStatus();

            // Fetch native TRX transfers for this wallet if TRX is supported
            if (TRC20TokenFilter::isTokenInSupportedList(TRC20Constants::CURRENCY_TRX)) {
                $trxCompositeId = sprintf('%s|%s', $rawWallet, TRC20Constants::CURRENCY_TRX);
                if (isset($this->accounts[$trxCompositeId])) {
                    $this->importJob->conversionStatus->addActivity(sprintf('Wallet %s: Fetching native TRX transfers...', $shortWallet));
                    $this->saveConversionStatus();
                    try {
                        $trxRequest = new GetTrxTransactionsRequest($apiKey, [$rawWallet]);
                        $trxRequest->setTimeOut((float)config('importer.connection.timeout'));
                        $trxDateFrom = $this->notBefore?->format('Y-m-d');
                        if (null === $trxDateFrom && $configuration->isIncrementalSyncEnabled()) {
                            $trxDateFrom = $this->resolveIncrementalDateFromCursor($rawWallet);
                        }
                        $trxTransactions = $this->downloadTrxTransactions(
                            $trxRequest,
                            $rawWallet,
                            $trxDateFrom,
                            $this->notAfter?->format('Y-m-d')
                        );
                        foreach ($trxTransactions as $tx) {
                            $txAccountId = $tx->account ?? $trxCompositeId;
                            $return[$txAccountId] ??= [];
                            $return[$txAccountId][] = $tx;
                        }
                        $this->importJob->conversionStatus->addActivity(
                            sprintf('Wallet %s: Fetched %d native TRX transfer(s)', $shortWallet, count($trxTransactions))
                        );
                    } catch (ImporterHttpException $e) {
                        $this->importJob->conversionStatus->addWarning(0, sprintf('Could not fetch TRX transfers: %s', $e->getMessage()));
                    }
                    $this->saveConversionStatus();
                }
            }
        }

        return $return;
    }

    public function getAccounts(): array
    {
        return $this->accounts;
    }

    public function setImportJob(ImportJob $importJob): void
    {
        $this->importJob = $importJob;
        $this->importJob->refreshInstanceIdentifier();
    }

    public function getImportJob(): ImportJob
    {
        return $this->importJob;
    }

    /**
     * Download native TRX transfers for a wallet using the general transactions endpoint.
     * Same pagination pattern as downloadWalletTransactions but uses GetTrxTransactionsRequest.
     */
    private function downloadTrxTransactions(
        GetTrxTransactionsRequest $request,
        string $wallet,
        ?string $dateFrom,
        ?string $dateTo
    ): array {
        $maxPages = max(1, (int)config('trc20.max_pages', 100));
        $page     = 0;
        $cursor   = null;
        $return   = [];
        $shortWallet = substr($wallet, 0, 8) . '...' . substr($wallet, -4);

        while ($page < $maxPages) {
            if (0 >= $request->getPageSize()) {
                break;
            }

            ++$page;
            $this->importJob->conversionStatus->addActivity(sprintf('Wallet %s: Fetching TRX page %d...', $shortWallet, $page));
            $this->saveConversionStatus();

            $response     = $request->get($dateFrom, $dateTo, $cursor);
            $rowCount     = count($response->getRawData());
            $transactions = $this->extractWalletTransactions($response, $wallet);

            $this->importJob->conversionStatus->addActivity(sprintf('Wallet %s: TRX page %d — %d rows, %d valid', $shortWallet, $page, $rowCount, count($transactions)));
            $this->saveConversionStatus();

            if (0 === $rowCount && false === $response->hasNextCursor()) {
                break;
            }

            $return = array_merge($return, $transactions);
            if (!$response->hasNextCursor()) {
                break;
            }

            $nextCursor = $response->getNextCursor();
            if (null === $nextCursor || '' === trim($nextCursor) || $nextCursor === $cursor) {
                break;
            }

            $cursor = $nextCursor;
        }

        return $return;
    }

    private function downloadWalletTransactions(
        GetTransactionsRequest $request,
        string $wallet,
        ?string $dateFrom,
        ?string $dateTo
    ): array {
        $maxPages = max(1, (int)config('trc20.max_pages', 100));
        $page     = 0;
        $cursor   = null;
        $return   = [];

        while ($page < $maxPages) {
            if (0 >= $request->getPageSize()) {
                break;
            }

            ++$page;
            $shortWallet  = substr($wallet, 0, 8) . '...' . substr($wallet, -4);
            $this->importJob->conversionStatus->addActivity(sprintf('Wallet %s: Fetching page %d from TronGrid...', $shortWallet, $page));
            $this->saveConversionStatus();

            $response     = $request->get($dateFrom, $dateTo, $cursor);
            $rowCount     = count($response->getRawData());
            $transactions = $this->extractWalletTransactions($response, $wallet);

            $this->importJob->conversionStatus->addActivity(sprintf('Wallet %s: Received %d rows, %d valid transactions (page %d)', $shortWallet, $rowCount, count($transactions), $page));
            $this->saveConversionStatus();

            if (0 === $rowCount && false === $response->hasNextCursor()) {
                break;
            }

            $return = array_merge($return, $transactions);
            if (!$response->hasNextCursor()) {
                break;
            }

            $nextCursor = $response->getNextCursor();
            if (null === $nextCursor || '' === trim($nextCursor) || $nextCursor === $cursor) {
                break;
            }

            $cursor = $nextCursor;
            Log::debug(sprintf('TRC20: Next cursor for wallet %s is "%s".', $wallet, $cursor));
        }

        return $return;
    }

    private function extractWalletTransactions(
        GetTransactionsResponse $response,
        string $wallet
    ): array {
        $return = [];

        foreach ($response->getRawData() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $normalized = $this->normalizeTransactionRow($row, $wallet);
            if (null === $normalized) {
                continue;
            }

            $transaction = $this->toTransactionObject($normalized);
            if (null === $transaction) {
                continue;
            }

            $externalId = $transaction->getTransactionId();
            if ('' === $externalId) {
                continue;
            }

            if (array_key_exists($externalId, $this->seenTransactionIds)) {
                Log::debug(sprintf('TRC20: skipping duplicate transaction %s for wallet %s.', $externalId, $wallet));

                continue;
            }
            $this->seenTransactionIds[$externalId] = true;
            $return[]                              = $transaction;

            // Add to live transaction board
            $shortTxId = strlen($externalId) > 14 ? substr($externalId, 0, 10) . '..' . substr($externalId, -2) : $externalId;
            $amount    = (string)($normalized['amount'] ?? '0');
            $merchant  = (string)($normalized['merchant'] ?? '');
            $shortMerchant = strlen($merchant) > 12 ? substr($merchant, 0, 8) . '..' . substr($merchant, -4) : $merchant;
            $this->importJob->conversionStatus->addBoardEntry([
                'tx_id'        => $shortTxId,
                'amount'       => $amount,
                'currency'     => (string)($normalized['currency'] ?? $normalized['token_symbol'] ?? ''),
                'direction'    => str_starts_with($amount, '-') ? 'outgoing' : 'incoming',
                'counterparty' => $shortMerchant,
                'date'         => (string)($normalized['date'] ?? ''),
                'status'       => 'fetched',
                'message'      => '',
            ]);
        }

        return $return;
    }

    /**
     * Convert a raw API row to normalized importer transaction fields.
     */
    private function normalizeTransactionRow(array $row, string $wallet): ?array
    {
        // accountId may be "wallet|SYMBOL" format from GetTransactionsRequest
        $rowAccountId = $this->normalizeWallet((string)($row['accountId'] ?? ''));
        $rowWallet    = str_contains($rowAccountId, '|') ? explode('|', $rowAccountId, 2)[0] : $rowAccountId;
        if ('' === $rowWallet || !TRC20AddressValidator::addressesMatch($rowWallet, $wallet)) {
            return null;
        }

        if (!TRC20TokenFilter::isSupported($row)) {
            Log::debug(sprintf('TRC20: skipped unsupported token transaction for wallet %s.', $wallet));

            return null;
        }

        $fromAddress = $this->normalizeWallet((string)($row['from_address'] ?? $row['ownerAddress'] ?? $row['fromAddress'] ?? $row['from'] ?? ''));
        $toAddress   = $this->normalizeWallet((string)($row['to_address'] ?? $row['toAddress'] ?? $row['to'] ?? ''));
        if ('' === $fromAddress && '' === $toAddress) {
            return null;
        }

        $amountStr = TRC20AmountParser::parse($row);
        if (null === $amountStr) {
            $this->importJob->conversionStatus->addWarning(
                0,
                sprintf('TRC20 transaction for wallet %s has no numeric amount and was ignored.', $wallet)
            );
            return null;
        }

        // Use bcmath to get absolute value: strip leading minus sign.
        $absAmount = ltrim($amountStr, '-');
        if (0 === bccomp($absAmount, '0', 12)) {
            $this->importJob->conversionStatus->addWarning(0, sprintf('Transaction in TRC20 row for %s has an amount of zero and was ignored.', $wallet));

            return null;
        }

        $date = $this->extractDate($row);
        if (null === $date) {
            $this->importJob->conversionStatus->addWarning(0, sprintf('TRC20 transaction for %s has invalid timestamp and was ignored.', $wallet));

            return null;
        }
        if (null !== $this->notBefore && $date->lt($this->notBefore)) {
            return null;
        }
        if (null !== $this->notAfter && $date->gt($this->notAfter)) {
            return null;
        }

        $isOutgoing = TRC20AddressValidator::addressesMatch($fromAddress, $wallet);
        if (!$isOutgoing && !TRC20AddressValidator::addressesMatch($toAddress, $wallet)) {
            return null;
        }

        // Build signed amount as plain decimal string (never scientific notation).
        // $absAmount is already a bcmath-safe string from TRC20AmountParser::parse().
        // Normalize trailing zeros so the amount string is stable for Firefly III's import_hash_v2.
        $signedAmount = self::normalizeAmountForStableHash($isOutgoing ? '-' . $absAmount : $absAmount);

        $counterparty = TRC20AddressValidator::addressesMatch($fromAddress, $wallet) ? $toAddress : $fromAddress;

        $txId = $this->extractTransactionId($row);
        if ('' === $txId) {
            try {
                $txId = $this->buildTransactionId(
                    $wallet,
                    $fromAddress,
                    $toAddress,
                    $signedAmount,
                    $date->format(self::DATE_TIME_FORMAT),
                    (string)($row['index'] ?? $row['tx_index'] ?? '')
                );
            } catch (\Throwable $e) {
                $this->importJob->conversionStatus->addWarning(0, sprintf('Could not build TRC20 transaction ID for %s.', $wallet));
                return null;
            }
        }
        if ('' === $txId) {
            $this->importJob->conversionStatus->addWarning(0, sprintf('TRC20 transaction for %s has no id and was ignored.', $wallet));

            return null;
        }
        // Use only the transaction hash as the external_id, not the wallet address.
        // The on-chain txHash is globally unique, and including the wallet would cause
        // the same transaction scanned from both sender and receiver wallets to produce
        // two different external_ids. Legacy format was "trc20|wallet|txHash" — the
        // TRC20 duplicate detector handles matching against old-format entries.
        $externalId = sprintf('trc20|%s', $txId);

        $memo        = $this->extractMemo($row);
        $description = trim((string)($row['data'] ?? $memo));
        if ('' === $description) {
            $description = sprintf('TRC20 %s transfer %s', $isOutgoing ? 'outgoing' : 'incoming', $txId);
        }

        $symbol = TRC20TokenFilter::extractSymbol($row);
        if ('' === $symbol) {
            $symbol = TRC20Constants::CURRENCY_USDT;
        }

        // Use per-token account ID (wallet|SYMBOL) to route to correct Firefly III account
        $perTokenAccountId = sprintf('%s|%s', $wallet, $symbol);

        return [
            'id'             => $externalId,
            'accountId'      => $perTokenAccountId,
            'amount'         => $signedAmount,
            'currency'       => $symbol,
            'date'           => $date->format('Y-m-d'),
            'merchant'       => $counterparty,
            'description'    => $description,
            'token_symbol'   => $symbol,
            'token_contract' => TRC20TokenFilter::extractContract($row),
            'from_address'   => $fromAddress,
            'to_address'     => $toAddress,
        ];
    }

    private function toTransactionObject(array $fields): ?Transaction
    {
        $transactionId = trim((string)($fields['id'] ?? ''));
        if ('' === $transactionId) {
            $this->importJob->conversionStatus->addWarning(
                0,
                sprintf('Could not normalize TRC20 transaction "%s": missing transaction id.', $fields['accountId'] ?? '')
            );

            return null;
        }
        if (!isset($fields['date']) || '' === trim((string)$fields['date'])) {
            $this->importJob->conversionStatus->addWarning(
                0,
                sprintf('Could not normalize TRC20 transaction "%s": invalid transaction date.', $transactionId)
            );

            return null;
        }
        try {
            return Transaction::fromArray($fields);
        } catch (\Throwable $e) {
            $this->importJob->conversionStatus->addWarning(
                0,
                sprintf('Could not normalize TRC20 transaction "%s" for account "%s".', $transactionId, $fields['accountId'] ?? '')
            );

            return null;
        }
    }

    private function extractTransactionId(array $row): string
    {
        return trim((string)($row['transaction_id'] ?? $row['txID'] ?? $row['tx_id'] ?? $row['hash'] ?? $row['id'] ?? ''));
    }

    private function buildTransactionId(
        string $wallet,
        string $fromAddress,
        string $toAddress,
        string $amount,
        string $date,
        string $index
    ): string {
        $seed = [$wallet, $fromAddress, $toAddress, $amount, $date];
        if ('' !== trim($index)) {
            $seed[] = trim($index);
        }

        return hash('sha256', json_encode($seed, JSON_THROW_ON_ERROR));
    }

    private function extractDate(array $row): ?Carbon
    {
        $rawDate = $row['date'] ?? $row['block_ts'] ?? $row['block_timestamp'] ?? $row['timestamp'] ?? $row['time'] ?? $row['created_at'] ?? null;
        if (null === $rawDate) {
            return null;
        }
        if (is_numeric($rawDate)) {
            $timestamp = (int)$rawDate;
            if ($timestamp > 9999999999) {
                $timestamp = intdiv($timestamp, 1000);
            }
            if ($timestamp < 0) {
                return null;
            }

            try {
                return Carbon::createFromTimestamp($timestamp);
            } catch (\Throwable $e) {
                return null;
            }
        }

        if (is_string($rawDate) && '' !== trim($rawDate)) {
            try {
                return Carbon::parse($rawDate);
            } catch (\Throwable $e) {
                return null;
            }
        }

        return null;
    }

    private function extractMemo(array $row): string
    {
        $memo = trim((string)($row['memo'] ?? $row['note'] ?? $row['txInfo'] ?? $row['contractData'] ?? ''));
        if ('' !== $memo) {
            return $memo;
        }

        if (is_array($row['memo_data'] ?? null) && array_key_exists('text', $row['memo_data'])) {
            return trim((string)$row['memo_data']['text']);
        }

        return '';
    }

    private function normalizeWallet(string $wallet): string
    {
        return trim($wallet);
    }

    /**
     * Strip trailing zeros from a decimal amount string so that Firefly III's
     * import_hash_v2 (SHA-256 of the full payload) produces the same hash
     * regardless of bcmath precision.  E.g. "123.450000000000" → "123.45".
     */
    public static function normalizeAmountForStableHash(string $amount): string
    {
        if (!str_contains($amount, '.')) {
            return $amount;
        }
        $normalized = rtrim(rtrim($amount, '0'), '.');

        return '' === $normalized || '-' === $normalized || '-0' === $normalized ? '0' : $normalized;
    }

    protected function getProviderName(): string
    {
        return 'TRC20';
    }

    protected function getContextCredentials(): array
    {
        $configuration = $this->importJob->getConfiguration();

        return [config('importer.version'), SecretManager::getApiKey($configuration), SharedSecretManager::getBaseUrl()];
    }

    private function loadServiceAccounts(\App\Services\Shared\Configuration\Configuration $configuration): array
    {
        $serviceAccounts = $this->importJob->getServiceAccounts();
        if (0 !== count($serviceAccounts)) {
            $normalized = [];
            foreach ($serviceAccounts as $serviceAccount) {
                $normalized[] = ImportServiceAccount::normalizeToArray($serviceAccount);
            }

            return $normalized;
        }

        $apiKey  = SecretManager::getApiKey($configuration);
        $wallets = array_keys($configuration->getAccounts());
        if (0 === count($wallets)) {
            return [];
        }

        $walletsRequest = new GetWalletsRequest($apiKey, $wallets);
        $walletsRequest->setTimeOut((float)config('importer.connection.timeout'));
        $response = $walletsRequest->get();

        $normalized = [];
        foreach ($response as $account) {
            $normalized[] = ImportServiceAccount::normalizeToArray($account);
        }

        return $normalized;
    }

}
