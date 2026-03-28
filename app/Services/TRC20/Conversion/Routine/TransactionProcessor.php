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
use App\Services\Shared\Preflight\ProviderCurrencyPreflightService;
use App\Services\Shared\SyncState\SyncStateManager;
use App\Services\TRC20\Authentication\SecretManager;
use App\Services\TRC20\Request\GetTransactionsRequest;
use App\Services\TRC20\Request\GetWalletsRequest;
use App\Services\TRC20\Support\TRC20AddressValidator;
use App\Services\LunchFlow\Model\Transaction;
use App\Services\TRC20\Response\GetTransactionsResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class TransactionProcessor
{
    use CreatesAccounts;

    private const string DATE_TIME_FORMAT = 'Y-m-d H:i:s';
    private const string CURRENCY_USDT = 'USDT';

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

        foreach ($accounts as $importServiceAccountId => $fireflyIIIAccountId) {
            $wallet = $this->normalizeWallet((string)$importServiceAccountId);
            if ('' === $wallet) {
                continue;
            }

            if (0 === (int)$fireflyIIIAccountId) {
                $createdAccount = $this->createOrFindExistingAccount($wallet);
                $updated        = $configuration->getAccounts();
                $updated[$wallet] = $createdAccount->id;
                $configuration->setAccounts($updated);
                $this->accounts[$wallet] = $createdAccount->id;
            } else {
                $this->accounts[$wallet] = (int)$fireflyIIIAccountId;
            }

            $apiKey  = SecretManager::getApiKey($configuration);
            $request = new GetTransactionsRequest($apiKey, [$wallet]);
            $request->setTimeOut((float)config('importer.connection.timeout'));
            $this->importJob->conversionStatus->setPullChecklistItem(
                $wallet,
                ConversionStatus::PULL_STEP_RUNNING,
                'Fetching transactions...'
            );
            $this->saveConversionStatus();

            try {
                $dateFrom = $this->notBefore?->format('Y-m-d');
                if (null === $dateFrom && $configuration->isIncrementalSyncEnabled()) {
                    $dateFrom = $this->resolveIncrementalDateFromCursor($wallet);
                }
                $transactions = $this->downloadWalletTransactions(
                    $request,
                    $wallet,
                    $dateFrom,
                    $this->notAfter?->format('Y-m-d')
                );
            } catch (ImporterHttpException $e) {
                $this->importJob->conversionStatus->addWarning(0, $e->getMessage());
                $this->importJob->conversionStatus->setPullChecklistItem(
                    $wallet,
                    ConversionStatus::PULL_STEP_ERROR,
                    sprintf('Could not fetch transactions from TRC20: %s', $e->getMessage())
                );
                $return[$wallet] = [];
                $this->importJob->conversionStatus->incrementPullProgress();
                $this->saveConversionStatus();

                continue;
            }

            $return[$wallet] = $transactions;
            $this->importJob->conversionStatus->addPullCursorCandidate(
                $wallet,
                $this->resolveLatestTransactionDate($transactions)
            );
            $this->importJob->conversionStatus->setPullChecklistItem(
                $wallet,
                ConversionStatus::PULL_STEP_DONE,
                sprintf('Fetched %d transaction(s)', count($transactions))
            );
            $this->importJob->conversionStatus->incrementPullProgress();
            $this->saveConversionStatus();
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
            $response     = $request->get($dateFrom, $dateTo, $cursor);
            $rowCount     = count($response->getRawData());
            $transactions = $this->extractWalletTransactions($response, $wallet);
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
        }

        return $return;
    }

    /**
     * Convert a raw API row to normalized importer transaction fields.
     */
    private function normalizeTransactionRow(array $row, string $wallet): ?array
    {
        $rowWallet = $this->normalizeWallet((string)($row['accountId'] ?? ''));
        if ('' === $rowWallet || ! TRC20AddressValidator::addressesMatch($rowWallet, $wallet)) {
            return null;
        }

        if (!$this->isUSDTTransaction($row)) {
            Log::debug(sprintf('TRC20: skipped non-USDT transaction row for wallet %s.', $wallet));

            return null;
        }

        $fromAddress = $this->normalizeWallet((string)($row['from_address'] ?? $row['ownerAddress'] ?? $row['fromAddress'] ?? $row['from'] ?? ''));
        $toAddress   = $this->normalizeWallet((string)($row['to_address'] ?? $row['toAddress'] ?? $row['to'] ?? ''));
        if ('' === $fromAddress && '' === $toAddress) {
            return null;
        }

        $amountRaw = $this->parseAmount($row);
        if (null === $amountRaw) {
            $this->importJob->conversionStatus->addWarning(
                0,
                sprintf('TRC20 transaction for wallet %s has no numeric amount and was ignored.', $wallet)
            );
            return null;
        }

        $amount = $this->normalizeAmount($amountRaw);
        if (0.0 === $amount) {
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

        $amount = $isOutgoing ? -1 * abs($amount) : abs($amount);

        $counterparty = TRC20AddressValidator::addressesMatch($fromAddress, $wallet) ? $toAddress : $fromAddress;

        $txId = $this->extractTransactionId($row);
        if ('' === $txId) {
            try {
                $txId = $this->buildTransactionId(
                    $wallet,
                    $fromAddress,
                    $toAddress,
                    $amount,
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
        $externalId = sprintf('trc20|%s|%s', $wallet, $txId);

        $memo        = $this->extractMemo($row);
        $description = trim((string)($row['data'] ?? $memo));
        if ('' === $description) {
            $description = sprintf('TRC20 %s transfer %s', $isOutgoing ? 'outgoing' : 'incoming', $txId);
        }

        $symbol = strtoupper(trim((string)($row['token_symbol'] ?? $row['currency'] ?? self::CURRENCY_USDT)));
        if ('' === $symbol) {
            $symbol = self::CURRENCY_USDT;
        }
        if (self::CURRENCY_USDT !== $symbol) {
            $this->importJob->conversionStatus->addWarning(0, sprintf('TRC20 transaction %s for %s was ignored due to non-USDT symbol.', $txId, $wallet));

            return null;
        }

        return [
            'id'             => $externalId,
            'accountId'      => $wallet,
            'amount'         => (string)$amount,
            'currency'       => $symbol,
            'date'           => $date->format('Y-m-d'),
            'merchant'       => $counterparty,
            'description'    => $description,
            'token_symbol'   => $symbol,
            'token_contract' => $this->resolveTokenContract($row),
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

    private function isUSDTTransaction(array $row): bool
    {
        $symbol = strtoupper(trim((string)($row['token_symbol'] ?? $row['currency'] ?? $row['token'] ?? $row['symbol'] ?? '')));
        if ('' !== $symbol && $symbol !== self::CURRENCY_USDT) {
            return false;
        }

        $contract = $this->resolveTokenContract($row);
        $configuredContract = strtolower(trim((string)config('trc20.usdt_contract_address', '')));

        if ('' === $configuredContract) {
            return true;
        }
        if ('' === $contract) {
            return false;
        }

        return $contract === $configuredContract;
    }

    private function extractTransactionId(array $row): string
    {
        return trim((string)($row['txID'] ?? $row['tx_id'] ?? $row['transaction_id'] ?? $row['hash'] ?? $row['id'] ?? ''));
    }

    private function buildTransactionId(
        string $wallet,
        string $fromAddress,
        string $toAddress,
        float $amount,
        string $date,
        string $index
    ): string {
        $seed = [
            $wallet,
            $fromAddress,
            $toAddress,
            $amount,
            $date,
        ];
        if ('' !== trim($index)) {
            $seed[] = trim($index);
        }

        return hash('sha256', json_encode($seed, JSON_THROW_ON_ERROR));
    }

    private function parseAmount(array $row): ?float
    {
        $rowAmount = $row['amount'] ?? null;
        if (null === $rowAmount) {
            $rowAmount = $row['value'] ?? null;
        }
        if (null === $rowAmount && is_array($row['tokenInfo'] ?? null)) {
            $rowAmount = $row['tokenInfo']['amount'] ?? null;
        }
        if (null === $rowAmount) {
            $rowAmount = $row['quant'] ?? null;
        }
        $amount    = $this->extractAmountValue($rowAmount);

        return null === $amount ? null : (float)$amount;
    }

    private function extractAmountValue(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }
        if (is_array($value) || is_object($value)) {
            return null;
        }

        $stringValue = trim((string)$value);
        if ('' === $stringValue) {
            return null;
        }
        if (!is_numeric($stringValue)) {
            return null;
        }

        return $stringValue;
    }

    private function normalizeAmount(float $amount): float
    {
        return round(abs($amount), 12);
    }

    private function extractDate(array $row): ?Carbon
    {
        $rawDate = $row['block_ts'] ?? $row['block_timestamp'] ?? $row['timestamp'] ?? $row['time'] ?? $row['created_at'] ?? null;
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

    private function resolveLatestTransactionDate(array $transactions): ?string
    {
        $latest = null;
        foreach ($transactions as $transaction) {
            if (!property_exists($transaction, 'date') || null === $transaction->date) {
                continue;
            }

            $date = $transaction->date;
            if (!($date instanceof Carbon)) {
                $date = Carbon::parse((string)$date);
            }

            if (null === $latest || $date->gt($latest)) {
                $latest = $date;
            }
        }

        return null === $latest ? null : $latest->toDateString();
    }

    private function saveConversionStatus(): void
    {
        $this->repository->saveToDisk($this->importJob);
    }

    private function buildContextFingerprint(): string
    {
        $configuration = $this->importJob->getConfiguration();
        $flow          = $this->importJob->getFlow();
        $apiKey        = SecretManager::getApiKey($configuration);
        $ffBaseUrl     = SharedSecretManager::getBaseUrl();

        return $this->syncStateManager->buildContextFingerprint(
            $flow,
            [config('importer.version'), $apiKey, $ffBaseUrl]
        );
    }

    private function resolveIncrementalDateFromCursor(string $accountId): ?string
    {
        $configuration = $this->importJob->getConfiguration();
        if ('' !== $configuration->getDateNotBefore()) {
            return null;
        }
        if (false === $configuration->isIncrementalSyncEnabled()) {
            return null;
        }

        $cursor = $this->syncStateManager->getLookBackDate('trc20', $this->contextFingerprint, $accountId);
        if (null === $cursor) {
            Log::debug(sprintf('No TRC20 cursor found for account %s.', $accountId));

            return null;
        }

        $lookbackDate = $this->syncStateManager->getIncrementalDateFromCursor($cursor, $configuration->getIncrementalLookbackDays());
        if (null === $lookbackDate) {
            return null;
        }

        Log::info(sprintf('Using incremental TRC20 pull date %s for account %s.', $lookbackDate, $accountId));

        return $lookbackDate;
    }

    private function loadServiceAccounts(\App\Services\Shared\Configuration\Configuration $configuration): array
    {
        $serviceAccounts = $this->importJob->getServiceAccounts();
        if (0 !== count($serviceAccounts)) {
            $normalized = [];
            foreach ($serviceAccounts as $serviceAccount) {
                $normalized[] = $this->normalizeServiceAccount($serviceAccount);
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
            $normalized[] = $this->normalizeServiceAccount($account);
        }

        return $normalized;
    }

    private function normalizeServiceAccount(array|object $account): array
    {
        if (is_array($account) && array_key_exists('currency_code', $account)) {
            return [
                'id'            => (string)($account['id'] ?? ''),
                'name'          => (string)($account['name'] ?? ''),
                'currency_code' => (string)($account['currency_code'] ?? ''),
                'iban'          => '',
                'bban'          => '',
                'status'        => (string)($account['status'] ?? 'active'),
                'extra'         => [],
            ];
        }

        $payload = [];
        if (is_array($account)) {
            $payload = $account;
        } elseif (is_object($account) && method_exists($account, 'toArray')) {
            $payload = (array)$account->toArray();
        }

        return [
            'id'            => (string)($payload['id'] ?? ''),
            'name'          => (string)($payload['name'] ?? ''),
            'currency_code' => (string)($payload['currency_code'] ?? $payload['currency'] ?? ''),
            'iban'          => '',
            'bban'          => '',
            'status'        => (string)($payload['status'] ?? 'active'),
            'extra'         => [],
        ];
    }

    private function resolveTokenContract(array $row): string
    {
        $tokenInfo = $row['tokenInfo'] ?? [];
        if (!is_array($tokenInfo)) {
            $tokenInfo = [];
        }

        return strtolower(trim((string)($row['token_contract'] ?? $tokenInfo['address'] ?? $tokenInfo['tokenId'] ?? $row['token_id'] ?? $row['contract_address'] ?? '')));
    }
}
