<?php

declare(strict_types=1);

namespace App\Services\Shared\Conversion;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Shared helpers extracted from BasisBank, TBank, and TRC20 TransactionProcessor classes.
 *
 * The using class MUST provide:
 *  - property ImportJob              $importJob
 *  - property SyncStateManager       $syncStateManager
 *  - property ImportJobRepository    $repository
 *  - property string                 $contextFingerprint
 */
trait TransactionProcessorHelpers
{
    /**
     * Return the provider name used in log messages and (lowercased) as the
     * sync-state namespace (e.g. 'BasisBank', 'TBank', 'TRC20').
     */
    abstract protected function getProviderName(): string;

    /**
     * Return the credential array fed into SyncStateManager::buildContextFingerprint().
     *
     * Typical shape: [config('importer.version'), $token, ..., SharedSecretManager::getBaseUrl()]
     */
    abstract protected function getContextCredentials(): array;

    /**
     * Find the latest date among a list of transaction objects.
     */
    protected function resolveLatestTransactionDate(array $transactions): ?string
    {
        $latest = null;
        foreach ($transactions as $transaction) {
            if (!property_exists($transaction, 'date') || null === $transaction->date) {
                continue;
            }

            $date = $transaction->date;
            if (!($date instanceof Carbon)) {
                $date = Carbon::parse((string) $date);
            }

            if (null === $latest || $date->gt($latest)) {
                $latest = $date;
            }
        }

        return null === $latest ? null : $latest->toDateString();
    }

    /**
     * Build a context fingerprint hash used for sync-state keying.
     */
    protected function buildContextFingerprint(): string
    {
        $flow = $this->importJob->getFlow();

        return $this->syncStateManager->buildContextFingerprint(
            $flow,
            $this->getContextCredentials()
        );
    }

    /**
     * Resolve the incremental sync "date from" using the stored cursor.
     */
    protected function resolveIncrementalDateFromCursor(string $accountId): ?string
    {
        $configuration = $this->importJob->getConfiguration();
        if ('' !== $configuration->getDateNotBefore()) {
            return null;
        }
        if (false === $configuration->isIncrementalSyncEnabled()) {
            return null;
        }

        $providerName    = $this->getProviderName();
        $providerNameKey = strtolower($providerName);
        $cursor          = $this->syncStateManager->getLookBackDate($providerNameKey, $this->contextFingerprint, $accountId);
        if (null === $cursor) {
            Log::debug(sprintf('No %s cursor found for account %s.', $providerName, $accountId));

            return null;
        }

        $lookbackDate = $this->syncStateManager->getIncrementalDateFromCursor($cursor, $configuration->getIncrementalLookbackDays());
        if (null === $lookbackDate) {
            return null;
        }

        Log::info(sprintf('Using incremental %s pull date %s for account %s.', $providerName, $lookbackDate, $accountId));

        return $lookbackDate;
    }

    /**
     * Persist the current conversion status to disk.
     */
    protected function saveConversionStatus(): void
    {
        $this->repository->saveToDisk($this->importJob);
    }
}
