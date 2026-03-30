<?php

declare(strict_types=1);

namespace App\Services\TBank\Conversion\Routine;

use App\Exceptions\ImporterErrorException;
use App\Exceptions\ImporterHttpException;
use App\Models\ImportJob;
use App\Repository\ImportJob\ImportJobRepository;
use App\Services\LunchFlow\Response\GetTransactionsResponse;
use App\Services\Shared\Authentication\SecretManager as SharedSecretManager;
use App\Services\Shared\Conversion\ConversionStatus;
use App\Services\Shared\Conversion\CreatesAccounts;
use App\Services\Shared\Preflight\ProviderCurrencyPreflightService;
use App\Services\Shared\Support\CurrencyCode;
use App\Services\Shared\SyncState\SyncStateManager;
use App\Services\TBank\Authentication\SecretManager;
use App\Services\TBank\Request\GetTransactionsRequest;
use App\Support\Internal\CollectsAccounts;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class TransactionProcessor
{
    use CollectsAccounts;
    use CreatesAccounts;

    private ImportJob $importJob;
    private array $accounts;
    private ?Carbon $notAfter = null;
    private ?Carbon $notBefore = null;
    private ImportJobRepository $repository;
    private SyncStateManager $syncStateManager;
    private string $contextFingerprint = '';

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
            $this->existingServiceAccounts = $this->getTBankAccounts($configuration);
            $this->importJob->setServiceAccounts($this->existingServiceAccounts);
        } catch (ImporterHttpException $e) {
            throw new ImporterErrorException(sprintf('Could not fetch TBank accounts: %s', $e->getMessage()), 0, $e);
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

            throw new ImporterErrorException('TBank preflight currency validation failed. Resolve account currency mismatches and retry import.');
        }

        foreach ($accounts as $importServiceAccountId => $fireflyIIIAccountId) {
            if (0 === (int)$fireflyIIIAccountId) {
                $createdAccount = $this->createOrFindExistingAccount((string)$importServiceAccountId);
                $updated        = $configuration->getAccounts();
                $updated[(string)$importServiceAccountId] = $createdAccount->id;
                $configuration->setAccounts($updated);
            }

            $sessionId = SecretManager::getSessionId($configuration);
            $cookieHeader = SecretManager::getCookieHeader($configuration);
            $request  = new GetTransactionsRequest($sessionId, $cookieHeader, (string)$importServiceAccountId);
            $request->setTimeOut((float)config('importer.connection.timeout'));
            $this->importJob->conversionStatus->setPullChecklistItem(
                (string)$importServiceAccountId,
                ConversionStatus::PULL_STEP_RUNNING,
                'Fetching transactions...'
            );
            $this->saveConversionStatus();

            try {
                $dateFrom = $this->notBefore?->format('Y-m-d');
                if (null === $dateFrom && $configuration->isIncrementalSyncEnabled()) {
                    $dateFrom = $this->resolveIncrementalDateFromCursor((string)$importServiceAccountId);
                }
                $transactions = $request->get($dateFrom, $this->notAfter?->format('Y-m-d'));
            } catch (ImporterHttpException $e) {
                $this->importJob->conversionStatus->addWarning(0, $e->getMessage());
                $return[(string)$importServiceAccountId] = [];
                $this->importJob->conversionStatus->setPullChecklistItem(
                    (string)$importServiceAccountId,
                    ConversionStatus::PULL_STEP_ERROR,
                    sprintf('Could not fetch transactions from TBank: %s', $e->getMessage())
                );
                $this->importJob->conversionStatus->incrementPullProgress();
                $this->saveConversionStatus();

                continue;
            }

            $expectedCurrency     = $this->resolveServiceAccountCurrency((string)$importServiceAccountId);
            $filteredTransactions = $this->filterTransactions($transactions, $expectedCurrency);
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
                sprintf('Fetched %d transaction(s)', count($filteredTransactions))
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
        $return = [];
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

        Log::debug(sprintf('TBank: %d transaction(s) remained after filtering.', count($return)));

        return $return;
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

    private function buildContextFingerprint(): string
    {
        $configuration = $this->importJob->getConfiguration();
        $flow          = $this->importJob->getFlow();
        $apiToken      = SecretManager::getSessionId($configuration);
        $cookieHeader  = SecretManager::getCookieHeader($configuration);
        $ffBaseUrl     = SharedSecretManager::getBaseUrl();

        return $this->syncStateManager->buildContextFingerprint(
            $flow,
            [config('importer.version'), $apiToken, $cookieHeader, $ffBaseUrl]
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

        $cursor = $this->syncStateManager->getLookBackDate('tbank', $this->contextFingerprint, $accountId);
        if (null === $cursor) {
            Log::debug(sprintf('No TBank cursor found for account %s.', $accountId));

            return null;
        }

        $lookbackDate = $this->syncStateManager->getIncrementalDateFromCursor($cursor, $configuration->getIncrementalLookbackDays());
        if (null === $lookbackDate) {
            return null;
        }

        Log::info(sprintf('Using incremental TBank pull date %s for account %s.', $lookbackDate, $accountId));

        return $lookbackDate;
    }

    private function resolveLatestTransactionDate(array $transactions): ?string
    {
        $latest = null;
        foreach ($transactions as $transaction) {
            if (!property_exists($transaction, 'date')) {
                continue;
            }
            $date = Carbon::parse((string)$transaction->date);
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

    private function resolveServiceAccountCurrency(string $serviceAccountId): string
    {
        foreach ($this->existingServiceAccounts as $serviceAccount) {
            if (is_object($serviceAccount)) {
                $id       = (string) ($serviceAccount->id ?? '');
                $currency = (string) ($serviceAccount->currency ?? '');
            } elseif (is_array($serviceAccount)) {
                $id       = (string) ($serviceAccount['id'] ?? '');
                $currency = (string) ($serviceAccount['currency'] ?? '');
            } else {
                continue;
            }

            if ($id !== $serviceAccountId) {
                continue;
            }

            return CurrencyCode::normalizeOrEmpty($currency);
        }

        return '';
    }
}
