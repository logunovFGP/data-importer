<?php

declare(strict_types=1);

namespace App\Services\BasisBank\Conversion\Routine;

use App\Exceptions\ImporterErrorException;
use App\Exceptions\ImporterHttpException;
use App\Models\ImportJob;
use App\Repository\ImportJob\ImportJobRepository;
use App\Services\BasisBank\Auth\BasisBankSessionState;
use App\Services\BasisBank\Authentication\SecretManager;
use App\Services\BasisBank\Request\GetTransactionsRequest;
use App\Services\Shared\Authentication\SecretManager as SharedSecretManager;
use App\Services\Shared\Configuration\Configuration;
use App\Services\Shared\Conversion\ConversionStatus;
use App\Services\LunchFlow\Response\GetTransactionsResponse;
use App\Services\Shared\Conversion\CreatesAccounts;
use App\Services\Shared\Conversion\TransactionProcessorHelpers;
use App\Services\Shared\Support\CurrencyCode;
use App\Services\Shared\SyncState\SyncStateManager;
use App\Support\Internal\CollectsAccounts;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class TransactionProcessor
{
    use CollectsAccounts;
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
    private array $pullTelemetryCheckpoint = [];

    /**
     * @throws ImporterErrorException
     */
    public function download(): array
    {
        $this->notBefore = null;
        $this->notAfter  = null;
        $this->accounts  = [];
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
            $this->existingServiceAccounts = $this->getBasisBankAccounts($configuration);
        } catch (ImporterHttpException $e) {
            throw new ImporterErrorException(sprintf('Could not fetch BasisBank accounts: %s', $e->getMessage()), 0, $e);
        }

        $accounts = $this->prepareMappingsAndValidateCurrencies($configuration, $accounts);

        foreach ($accounts as $importServiceAccountId => $fireflyIIIAccountId) {

            $apiToken  = SecretManager::getApiToken($configuration);
            $consentId = SecretManager::getConsentId($configuration);
            $request   = new GetTransactionsRequest(
                $apiToken,
                $consentId,
                (string)$importServiceAccountId,
                SecretManager::getSessionArtifact($configuration)
            );
            $request->setTimeOut((float)config('importer.connection.timeout'));
            $request->setProgressReporter(function (array $payload) use ($importServiceAccountId): void {
                $this->applyPullTelemetry((string)$importServiceAccountId, $payload);
            });
            $this->importJob->conversionStatus->setPullChecklistItem(
                (string)$importServiceAccountId,
                ConversionStatus::PULL_STEP_RUNNING,
                'Fetching transactions...',
                [
                    'stage'            => 'account_start',
                    'stream'           => 'booked',
                    'fetched_total'    => 0,
                    'progress_percent' => 0,
                    'indeterminate'    => true,
                ]
            );
            $this->saveConversionStatus();

            try {
                $dateFrom = $this->notBefore?->format('Y-m-d');
                if (null === $dateFrom && $configuration->isIncrementalSyncEnabled()) {
                    $dateFrom = $this->resolveIncrementalDateFromCursor((string)$importServiceAccountId);
                }
                $transactions = $request->get($dateFrom, $this->notAfter?->format('Y-m-d'));
            } catch (ImporterHttpException $e) {
                if ($this->requiresReAuthentication($e)) {
                    SecretManager::saveAuthState(BasisBankSessionState::UNKNOWN);
                    SecretManager::saveSessionArtifact('');
                    $reauthMessage = 'BasisBank authentication session expired. Please authenticate BasisBank again and restart conversion.';
                    $this->importJob->conversionStatus->addError(0, $reauthMessage);
                    $this->importJob->conversionStatus->setPullChecklistItem(
                        (string)$importServiceAccountId,
                        ConversionStatus::PULL_STEP_ERROR,
                        $reauthMessage,
                        [
                            'stage'            => 'reauth_required',
                            'progress_percent' => 100,
                            'indeterminate'    => false,
                        ]
                    );
                    $this->saveConversionStatus();

                    throw new ImporterErrorException($reauthMessage, 0, $e);
                }
                $errorMessage = $e->getMessage();
                if ((int)$e->statusCode > 0) {
                    $errorMessage = sprintf('HTTP %d: %s', (int)$e->statusCode, $errorMessage);
                }
                $this->importJob->conversionStatus->addWarning(0, $errorMessage);
                $this->importJob->conversionStatus->setPullChecklistItem(
                    (string)$importServiceAccountId,
                    ConversionStatus::PULL_STEP_ERROR,
                    sprintf('Could not fetch transactions from BasisBank: %s', $errorMessage),
                    [
                        'stage'            => 'account_error',
                        'progress_percent' => 100,
                        'indeterminate'    => false,
                    ]
                );
                $return[(string)$importServiceAccountId] = [];
                $this->importJob->conversionStatus->incrementPullProgress();
                $this->saveConversionStatus();

                continue;
            }

            $expectedCurrency     = $this->readServiceAccountCurrency((string)$importServiceAccountId);
            $filteredTransactions = $this->filterTransactions($transactions, $expectedCurrency);
            $filteredTransactions = $this->deduplicateByDescriptionDate($filteredTransactions);
            $return[(string)$importServiceAccountId] = $filteredTransactions;
            $latestTransactionDate = $this->resolveLatestTransactionDate($filteredTransactions);
            if (null !== $latestTransactionDate) {
                $this->importJob->conversionStatus->addPullCursorCandidate(
                    (string)$importServiceAccountId,
                    $latestTransactionDate
                );
            }
            $this->importJob->conversionStatus->setPullChecklistItem(
                (string)$importServiceAccountId,
                ConversionStatus::PULL_STEP_DONE,
                sprintf('Fetched %d transaction(s)', count($filteredTransactions)),
                [
                    'stage'            => 'account_done',
                    'stream'           => 'booked',
                    'fetched_total'    => count($filteredTransactions),
                    'progress_percent' => 100,
                    'indeterminate'    => false,
                ]
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

    private function filterTransactions(GetTransactionsResponse $transactions, string $expectedCurrency = ''): array
    {
        $configuration = $this->importJob->getConfiguration();
        $return        = [];
        $getPending    = $configuration->getPendingTransactions();
        if ($getPending) {
            Log::info('BasisBank: pending transactions are enabled.');
        }
        $expectedCurrency = CurrencyCode::normalizeOrEmpty($expectedCurrency);

        foreach ($transactions as $transaction) {
            $madeOn = $transaction->getDate();
            $transactionCurrency = CurrencyCode::normalizeOrEmpty((string)($transaction->currency ?? ''));

            if ($this->notBefore instanceof Carbon && $madeOn->lt($this->notBefore)) {
                continue;
            }
            if ($this->notAfter instanceof Carbon && $madeOn->gt($this->notAfter)) {
                continue;
            }
            if ('' !== $expectedCurrency && '' !== $transactionCurrency && $transactionCurrency !== $expectedCurrency) {
                continue;
            }
            if (0 === bccomp('0', $transaction->amount)) {
                $this->importJob->conversionStatus->addWarning(0, sprintf('Transaction #%s has an amount of zero and was ignored.', $transaction->id));

                continue;
            }

            $return[] = $transaction;
        }

        Log::debug(sprintf('BasisBank: %d transaction(s) remained after filtering.', count($return)));

        return $return;
    }

    /**
     * Remove within-batch duplicates where description and date are identical.
     *
     * BasisBank sometimes returns the same transaction with a different TransactionID
     * across successive page loads or AJAX responses. The external_id dedup in ApiSubmitter
     * catches cross-import duplicates, but this layer catches within-batch duplicates that
     * have different external_ids but represent the same real transaction.
     */
    private function deduplicateByDescriptionDate(array $transactions): array
    {
        $seen   = [];
        $result = [];
        $skipped = 0;

        foreach ($transactions as $transaction) {
            $externalId  = trim((string)($transaction->id ?? ''));
            $description = trim((string)($transaction->description ?? ''));
            $date        = $transaction->getDate()->toDateString();
            $amount      = (string)($transaction->amount ?? '0');
            // Composite key: description + date + amount to avoid false positives
            // on genuinely different transactions with the same description on the same day.
            $key = md5($description . '|' . $date . '|' . $amount);

            if (isset($seen[$key])) {
                $previousExternalId = $seen[$key];
                // If both have distinct non-empty external IDs, they are genuinely different transactions.
                if ('' !== $externalId && '' !== $previousExternalId && $externalId !== $previousExternalId) {
                    $result[] = $transaction;
                    continue;
                }
                $skipped++;
                $this->importJob->conversionStatus->addWarning(
                    0,
                    sprintf(
                        'Within-batch duplicate skipped (description+date+amount): "%s" on %s (%s)',
                        mb_substr($description, 0, 60),
                        $date,
                        $amount
                    )
                );
                continue;
            }

            $seen[$key] = $externalId;
            $result[]   = $transaction;
        }

        if ($skipped > 0) {
            Log::info(sprintf('BasisBank: %d within-batch duplicate(s) removed by description+date+amount.', $skipped));
        }

        return $result;
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
     * Resolve account currencies before full pull and block entire job on mismatch.
     *
     * @throws ImporterErrorException
     */
    private function prepareMappingsAndValidateCurrencies(Configuration $configuration, array $accounts): array
    {
        $updatedAccounts          = $accounts;
        $newAccounts              = $configuration->getNewAccounts();
        $createdAccountCurrencies = [];
        $serviceAccountsChanged   = false;
        $newAccountsChanged       = false;
        $preflightErrors          = [];

        foreach ($updatedAccounts as $importServiceAccountId => $fireflyIIIAccountId) {
            $serviceAccountId = (string)$importServiceAccountId;
            $serviceCurrency  = $this->resolveServiceAccountCurrency($serviceAccountId, $configuration);
            if ('' !== $serviceCurrency) {
                $serviceAccountsChanged = $this->updateServiceAccountCurrency($serviceAccountId, $serviceCurrency) || $serviceAccountsChanged;
                if ($this->updateNewAccountCurrency($serviceAccountId, $serviceCurrency, $newAccounts)) {
                    $newAccountsChanged = true;
                }
            }

            if (0 === (int)$fireflyIIIAccountId) {
                if ($newAccountsChanged) {
                    $configuration->setNewAccounts($newAccounts);
                }
                $this->importJob->setConfiguration($configuration);
                $createdAccount                          = $this->createOrFindExistingAccount($serviceAccountId);
                $updatedAccounts[$serviceAccountId]      = $createdAccount->id;
                $configuration->setAccounts($updatedAccounts);
                $fireflyIIIAccountId                     = $createdAccount->id;
                $createdAccountCurrencies[(string)$createdAccount->id] = CurrencyCode::normalizeOrEmpty((string)($createdAccount->currencyCode ?? ''));
            }

            $mappedCurrency = $this->resolveFireflyAccountCurrency((int)$fireflyIIIAccountId, $createdAccountCurrencies);

            if ('' === $serviceCurrency) {
                $preflightErrors[$serviceAccountId] = [
                    'short' => 'Currency unknown: could not infer from account payload or first 10 transactions.',
                    'full'  => sprintf(
                        'BasisBank preflight blocked account "%s": source currency is unknown. The importer checked account metadata and sampled first 10 transactions but could not infer a stable currency. Please verify account currency in BasisBank, remap this account, then retry.',
                        $serviceAccountId
                    ),
                ];

                continue;
            }
            if ('' === $mappedCurrency) {
                $preflightErrors[$serviceAccountId] = [
                    'short' => sprintf('Mapped Firefly account #%d has unknown currency metadata.', (int)$fireflyIIIAccountId),
                    'full'  => sprintf(
                        'BasisBank preflight blocked account "%s": source currency is "%s" but mapped Firefly account #%d has unknown currency metadata. Update Firefly account settings via API (PATCH /api/v1/accounts/%d) or remap this BasisBank account, then retry.',
                        $serviceAccountId,
                        $serviceCurrency,
                        (int)$fireflyIIIAccountId,
                        (int)$fireflyIIIAccountId
                    ),
                ];

                continue;
            }
            if ($serviceCurrency !== $mappedCurrency) {
                $preflightErrors[$serviceAccountId] = [
                    'short' => sprintf('Currency mismatch: source %s, mapped #%d is %s.', $serviceCurrency, (int)$fireflyIIIAccountId, $mappedCurrency),
                    'full'  => sprintf(
                        'BasisBank preflight blocked account "%s": source currency "%s" does not match mapped Firefly account #%d currency "%s". Update Firefly account via API (PATCH /api/v1/accounts/%d with {"currency_code":"%s"}) or remap this BasisBank account to a %s account, then retry.',
                        $serviceAccountId,
                        $serviceCurrency,
                        (int)$fireflyIIIAccountId,
                        $mappedCurrency,
                        (int)$fireflyIIIAccountId,
                        $serviceCurrency,
                        $serviceCurrency
                    ),
                ];
            }
        }

        if ($newAccountsChanged) {
            $configuration->setNewAccounts($newAccounts);
        }
        $configuration->setAccounts($updatedAccounts);
        $this->importJob->setConfiguration($configuration);
        if ($serviceAccountsChanged) {
            $this->importJob->setServiceAccounts($this->existingServiceAccounts);
        }

        if ([] !== $preflightErrors) {
            foreach ($preflightErrors as $accountId => $error) {
                $this->importJob->conversionStatus->setPullChecklistItem(
                    (string)$accountId,
                    ConversionStatus::PULL_STEP_ERROR,
                    (string)$error['short']
                );
                $this->importJob->conversionStatus->addError(0, (string)$error['full']);
            }
            $this->saveConversionStatus();

            throw new ImporterErrorException('BasisBank preflight currency validation failed. Resolve account currency mismatches and retry import.');
        }

        $this->saveConversionStatus();

        return $updatedAccounts;
    }

    /**
     * @throws ImporterErrorException
     */
    private function resolveServiceAccountCurrency(string $serviceAccountId, Configuration $configuration): string
    {
        $existing = $this->readServiceAccountCurrency($serviceAccountId);
        if ('' !== $existing) {
            return $existing;
        }

        return $this->inferServiceAccountCurrencyFromTransactions($serviceAccountId, $configuration);
    }

    private function readServiceAccountCurrency(string $serviceAccountId): string
    {
        foreach ($this->existingServiceAccounts as $account) {
            if (!$this->serviceAccountMatches($account, $serviceAccountId)) {
                continue;
            }
            if (is_array($account)) {
                $direct = CurrencyCode::normalizeOrEmpty((string)($account['currency'] ?? $account['currency_code'] ?? ''));
                if ('' !== $direct) {
                    return $direct;
                }
                $fromExtra = CurrencyCode::normalizeOrEmpty((string)(is_array($account['extra'] ?? null) ? ($account['extra']['Currency'] ?? '') : ''));
                if ('' !== $fromExtra) {
                    return $fromExtra;
                }
            }
            if (is_object($account)) {
                $direct = CurrencyCode::normalizeOrEmpty((string)($account->currency ?? $account->currencyCode ?? $account->currency_code ?? ''));
                if ('' !== $direct) {
                    return $direct;
                }
            }
        }

        return '';
    }

    /**
     * @throws ImporterErrorException
     */
    private function inferServiceAccountCurrencyFromTransactions(string $serviceAccountId, Configuration $configuration): string
    {
        $apiToken        = SecretManager::getApiToken($configuration);
        $consentId       = SecretManager::getConsentId($configuration);
        $sessionArtifact = SecretManager::getSessionArtifact($configuration);
        $request         = new GetTransactionsRequest($apiToken, $consentId, $serviceAccountId, $sessionArtifact);
        $request->setTimeOut((float)config('importer.connection.timeout'));

        try {
            $detected = CurrencyCode::normalizeOrEmpty($request->detectAccountCurrency(null, null, 10));
            if ('' !== $detected) {
                Log::info(sprintf('BasisBank currency preflight: inferred currency "%s" from first sampled transactions for account %s.', $detected, $serviceAccountId));
            }

            return $detected;
        } catch (ImporterHttpException $e) {
            if ($this->requiresReAuthentication($e)) {
                SecretManager::saveAuthState(BasisBankSessionState::UNKNOWN);
                SecretManager::saveSessionArtifact('');
                throw new ImporterErrorException(
                    sprintf('BasisBank authentication session expired during currency preflight for account %s. Please authenticate again and retry.', $serviceAccountId),
                    0,
                    $e
                );
            }
            Log::warning(sprintf('BasisBank currency preflight: failed to sample account %s: %s', $serviceAccountId, $e->getMessage()));

            return '';
        }
    }

    private function updateServiceAccountCurrency(string $serviceAccountId, string $currency): bool
    {
        $changed = false;
        $normalizedCurrency = CurrencyCode::normalizeOrEmpty($currency);
        if ('' === $normalizedCurrency) {
            return false;
        }

        foreach ($this->existingServiceAccounts as $index => $account) {
            if (!$this->serviceAccountMatches($account, $serviceAccountId)) {
                continue;
            }
            if (is_array($account)) {
                $current = CurrencyCode::normalizeOrEmpty((string)($account['currency'] ?? $account['currency_code'] ?? ''));
                if ($current === $normalizedCurrency) {
                    continue;
                }
                $account['currency']      = $normalizedCurrency;
                $account['currency_code'] = $normalizedCurrency;
                if (!isset($account['extra']) || !is_array($account['extra'])) {
                    $account['extra'] = [];
                }
                $account['extra']['Currency'] = $normalizedCurrency;
                $this->existingServiceAccounts[$index] = $account;
                $changed = true;
                continue;
            }
            if (is_object($account)) {
                $current = CurrencyCode::normalizeOrEmpty((string)($account->currency ?? $account->currencyCode ?? $account->currency_code ?? ''));
                if ($current === $normalizedCurrency) {
                    continue;
                }
                if (property_exists($account, 'currency')) {
                    $account->currency = $normalizedCurrency;
                }
                if (property_exists($account, 'currencyCode')) {
                    $account->currencyCode = $normalizedCurrency;
                }
                if (property_exists($account, 'currency_code')) {
                    $account->currency_code = $normalizedCurrency;
                }
                $this->existingServiceAccounts[$index] = $account;
                $changed = true;
            }
        }

        return $changed;
    }

    private function updateNewAccountCurrency(string $serviceAccountId, string $currency, array &$newAccounts): bool
    {
        if (!array_key_exists($serviceAccountId, $newAccounts) || !is_array($newAccounts[$serviceAccountId])) {
            return false;
        }

        $normalizedCurrency = CurrencyCode::normalizeOrEmpty($currency);
        if ('' === $normalizedCurrency) {
            return false;
        }
        $current = CurrencyCode::normalizeOrEmpty((string)($newAccounts[$serviceAccountId]['currency'] ?? ''));
        if ($current === $normalizedCurrency) {
            return false;
        }
        $newAccounts[$serviceAccountId]['currency'] = $normalizedCurrency;

        return true;
    }

    private function resolveFireflyAccountCurrency(int $fireflyAccountId, array $createdAccountCurrencies): string
    {
        if ($fireflyAccountId <= 0) {
            return '';
        }
        $key = (string)$fireflyAccountId;
        if (array_key_exists($key, $createdAccountCurrencies) && '' !== $createdAccountCurrencies[$key]) {
            return CurrencyCode::normalizeOrEmpty((string)$createdAccountCurrencies[$key]);
        }

        $applicationAccounts = $this->importJob->getApplicationAccounts();
        $groups              = ['assets', 'asset_accounts', 'liabilities'];
        foreach ($groups as $group) {
            if (!is_array($applicationAccounts[$group] ?? null)) {
                continue;
            }
            foreach ($applicationAccounts[$group] as $account) {
                $currentId = 0;
                $currency  = '';
                if (is_array($account)) {
                    $currentId = (int)($account['id'] ?? 0);
                    $currency  = (string)($account['currency_code'] ?? $account['currencyCode'] ?? '');
                } elseif (is_object($account)) {
                    $currentId = (int)($account->id ?? 0);
                    $currency  = (string)($account->currencyCode ?? $account->currency_code ?? '');
                }
                if ($currentId !== $fireflyAccountId) {
                    continue;
                }

                return CurrencyCode::normalizeOrEmpty($currency);
            }
        }

        return '';
    }

    private function serviceAccountMatches(array|object $account, string $serviceAccountId): bool
    {
        $targetIds = $this->collectNormalizedServiceAccountIdsFromRaw($serviceAccountId);
        if ([] === $targetIds) {
            return false;
        }
        $candidateIds = $this->collectServiceAccountIdentifiers($account);
        foreach ($candidateIds as $candidateId) {
            if (in_array($candidateId, $targetIds, true)) {
                return true;
            }
        }

        return false;
    }

    private function collectServiceAccountIdentifiers(array|object $account): array
    {
        $ids = [];
        if (is_array($account)) {
            $ids[] = (string)($account['id'] ?? '');
            $syncIds = $account['sync_ids'] ?? [];
            if (is_array($syncIds)) {
                foreach ($syncIds as $syncId) {
                    $ids[] = (string)$syncId;
                }
            }
        }
        if (is_object($account)) {
            $ids[] = (string)($account->id ?? '');
            $syncIds = $account->sync_ids ?? [];
            if (is_array($syncIds)) {
                foreach ($syncIds as $syncId) {
                    $ids[] = (string)$syncId;
                }
            }
        }

        $normalized = [];
        foreach ($ids as $id) {
            foreach ($this->collectNormalizedServiceAccountIdsFromRaw($id) as $variant) {
                $normalized[$variant] = true;
            }
        }

        return array_keys($normalized);
    }

    private function collectNormalizedServiceAccountIdsFromRaw(string $rawId): array
    {
        $trimmed = trim($rawId);
        if ('' === $trimmed) {
            return [];
        }
        $normalized = strtoupper(str_replace(' ', '', $trimmed));
        $variants   = [$normalized];
        if (preg_match('/^BB-(?:CARD|ACCOUNT)-(.+)$/i', $normalized, $matches) === 1) {
            $suffix = trim((string)$matches[1]);
            if ('' !== $suffix) {
                $variants[] = $suffix;
            }
        }

        return array_values(array_unique($variants));
    }

    protected function getProviderName(): string
    {
        return 'BasisBank';
    }

    protected function getContextCredentials(): array
    {
        $configuration = $this->importJob->getConfiguration();

        return [config('importer.version'), SecretManager::getApiToken($configuration), SecretManager::getConsentId($configuration), SharedSecretManager::getBaseUrl()];
    }

    private function applyPullTelemetry(string $accountId, array $payload): void
    {
        $stage = trim((string)($payload['stage'] ?? ''));
        if ('' === $stage) {
            return;
        }
        $checkpoint = implode('|', [
            $stage,
            (string)($payload['blocked_only'] ?? ''),
            (string)($payload['page'] ?? ''),
            (string)($payload['page_rows'] ?? ''),
            (string)($payload['fetched_total'] ?? ''),
            (string)($payload['parsed_rows'] ?? ''),
        ]);
        if (($this->pullTelemetryCheckpoint[$accountId] ?? null) === $checkpoint) {
            return;
        }
        $this->pullTelemetryCheckpoint[$accountId] = $checkpoint;
        $message = $this->buildTelemetryMessage($payload);
        if ('' === $message) {
            return;
        }
        $this->importJob->conversionStatus->setPullChecklistItem(
            $accountId,
            ConversionStatus::PULL_STEP_RUNNING,
            $message,
            $this->buildTelemetryMeta($payload)
        );
        $this->saveConversionStatus();
    }

    private function buildTelemetryMessage(array $payload): string
    {
        $stage       = trim((string)($payload['stage'] ?? ''));
        $isPending   = true === ($payload['blocked_only'] ?? false);
        $streamLabel = $isPending ? 'pending' : 'booked';
        $page        = max(0, (int)($payload['page'] ?? 0));
        $fetched     = max(0, (int)($payload['fetched_total'] ?? 0));
        $parsed      = max(0, (int)($payload['parsed_rows'] ?? 0));

        return match ($stage) {
            'cardmodule_start' => sprintf('Fetching %s transactions...', $streamLabel),
            'cardmodule_page'  => $page > 0
                ? sprintf('Fetching %s transactions... page %d, fetched %d transaction(s) so far', $streamLabel, $page, $fetched)
                : sprintf('Fetching %s transactions... fetched %d transaction(s) so far', $streamLabel, $fetched),
            'cardmodule_done'  => sprintf('Finished %s stream. Fetched %d transaction(s) so far.', $streamLabel, $fetched),
            'statement_fallback' => 'CardModule did not return final data. Falling back to statement page parser...',
            'statement_start'  => 'Statement-page fallback is running...',
            'statement_done'   => sprintf('Statement-page fallback parsed %d transaction(s).', $parsed),
            default            => '',
        };
    }

    private function buildTelemetryMeta(array $payload): array
    {
        $stage = trim((string)($payload['stage'] ?? ''));
        $meta  = [
            'stage'         => $stage,
            'stream'        => true === ($payload['blocked_only'] ?? false) ? 'pending' : 'booked',
            'page'          => max(0, (int)($payload['page'] ?? 0)),
            'page_rows'     => max(0, (int)($payload['page_rows'] ?? 0)),
            'fetched_total' => max(0, (int)($payload['fetched_total'] ?? 0)),
            'parsed_rows'   => max(0, (int)($payload['parsed_rows'] ?? 0)),
            'statement_id'  => trim((string)($payload['statement_id'] ?? '')),
            'max_pages'     => max(0, (int)($payload['max_pages'] ?? 0)),
            'indeterminate' => true,
        ];

        if ('statement_done' === $stage) {
            $meta['progress_percent'] = 95;
            $meta['indeterminate']    = false;

            return $meta;
        }
        if ('cardmodule_done' === $stage) {
            $meta['progress_percent'] = 99;
            $meta['indeterminate']    = false;

            return $meta;
        }
        if ('cardmodule_page' === $stage && $meta['max_pages'] > 0 && $meta['page'] > 0) {
            $meta['progress_percent'] = max(1, min(99, (int)round(($meta['page'] / $meta['max_pages']) * 100)));
            $meta['indeterminate']    = false;
        }

        return $meta;
    }

    private function requiresReAuthentication(ImporterHttpException $e): bool
    {
        if (in_array((int)$e->statusCode, [302, 401, 403, 440], true)) {
            return true;
        }
        $message = strtolower($e->getMessage());
        $needles = [
            'session expired',
            'authentication required',
            'requires login',
            'returned login form',
            'not authorized',
            'deadsession',
            'web session is not available',
            'session artifact',
            'redirect location',
            'returned http 302',
            'requires re-authentication',
            'trusted-device',
            'otp code',
        ];
        foreach ($needles as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}
