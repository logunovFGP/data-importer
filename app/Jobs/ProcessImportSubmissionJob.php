<?php

/*
 * ProcessImportSubmissionJob.php
 * Copyright (c) 2025 james@firefly-iii.org
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ImportJob;
use App\Repository\ImportJob\ImportJobRepository;
use App\Services\BasisBank\Authentication\SecretManager as BasisSecretManager;
use App\Services\Shared\Import\Routine\RoutineManager;
use App\Services\Shared\Import\Status\SubmissionStatus;
use App\Services\Shared\Authentication\SecretManager as FireflySecretManager;
use App\Services\Shared\SyncState\SyncStateManager;
use App\Services\TRC20\Authentication\SecretManager as TRC20SecretManager;
use App\Services\TBank\Authentication\SecretManager as TBankSecretManager;
use Carbon\Carbon;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessImportSubmissionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries   = 1;
    private ImportJobRepository $repository;

    /**
     * The maximum number of seconds the job can run for.
     */
    public int $timeout = 1800;

    /**
     * Create a new job instance.
     */
    public function __construct(private ImportJob $importJob, private string $accessToken, private string $baseUrl, private ?string $vanityUrl, private ?string $submissionLockKey = null)
    {
        $this->importJob->refreshInstanceIdentifier();
        $this->repository = new ImportJobRepository();
        $this->repository->saveToDisk($this->importJob);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('ProcessImportSubmissionJob started', [
            'identifier'        => $this->importJob->identifier,
            'transaction_count' => count($this->importJob->getConvertedTransactions()),
        ]);

        // Validate authentication credentials before proceeding
        if ('' === $this->accessToken) {
            throw new Exception('Access token is empty - cannot authenticate with Firefly III');
        }

        if ('' === $this->baseUrl) {
            throw new Exception('Base URL is empty - cannot connect to Firefly III');
        }

        try {
            // Set initial running status
            $this->importJob->submissionStatus->setStatus(SubmissionStatus::SUBMISSION_RUNNING);
            $this->repository->saveToDisk($this->importJob);
            $this->prepareBasisBankRuntimeContext();
            // Initialize routine manager and execute import
            $routine         = new RoutineManager($this->importJob);

            Log::debug(sprintf('Starting submission routine execution for import job "%s"', $this->importJob->identifier));

            // Execute the import process
            $routine->start();

            // get the import job back just in case.
            $this->importJob = $routine->getImportJob();
            $this->updateSyncState();

            // Set completion status
            $this->importJob->submissionStatus->setStatus(SubmissionStatus::SUBMISSION_DONE);
            $this->repository->saveToDisk($this->importJob);

            // FIXME no longer necessary to collect all messages etc, it is in the importjob anway.
            Log::info('ProcessImportSubmissionJob completed successfully', ['identifier' => $this->importJob->identifier]);
        } catch (Throwable $e) {
            Log::error('ProcessImportSubmissionJob failed', [
                'identifier' => $this->importJob->identifier,
                'error'      => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);

            // Set error status
            $this->importJob->submissionStatus->setStatus(SubmissionStatus::SUBMISSION_ERRORED);
            $this->repository->saveToDisk($this->importJob);

            // Re-throw to mark job as failed
            throw $e;
        } finally {
            $this->releaseSubmissionLock();
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('ProcessImportSubmissionJob marked as failed', [
            'identifier' => $this->importJob->identifier,
            'exception'  => $exception->getMessage(),
        ]);

        // Ensure error status is set even if job fails catastrophically
        $this->importJob->submissionStatus->setStatus(SubmissionStatus::SUBMISSION_ERRORED);
        $this->releaseSubmissionLock();
    }

    /**
     * Get the tags that should be assigned to the job.
     *
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['import-submission', $this->identifier];
    }

    private function updateSyncState(): void
    {
        $flow       = $this->importJob->getFlow();
        if ('basisbank' !== $flow && 'tbank' !== $flow && 'trc20' !== $flow) {
            return;
        }

        $cursorCandidates = $this->importJob->conversionStatus->getPullCursorCandidates();
        if (0 === count($cursorCandidates)) {
            Log::debug('No cursor candidates found, skipping sync-state update.');
            return;
        }

        $syncStateManager = new SyncStateManager();
        $configuration    = $this->importJob->getConfiguration();
        $fingerprint      = $this->buildSyncStateFingerprint($flow, $configuration);

        foreach ($cursorCandidates as $accountId => $cursorCandidate) {
            if (!is_string($cursorCandidate) || '' === $cursorCandidate) {
                continue;
            }
            try {
                $syncStateManager->setLookBackDate($flow, $fingerprint, (string)$accountId, Carbon::parse($cursorCandidate));
                Log::info(sprintf('Updated sync cursor for %s account %s to %s.', $flow, $accountId, $cursorCandidate));
            } catch (\Throwable $exception) {
                Log::warning(sprintf('Could not update sync cursor for account %s: %s', $accountId, $exception->getMessage()));
            }
        }
    }

    private function prepareBasisBankRuntimeContext(): void
    {
        if ('basisbank' !== $this->importJob->getFlow()) {
            return;
        }

        $configuration = $this->importJob->getConfiguration();
        if (null === $configuration) {
            return;
        }

        $login          = trim((string)$configuration->getBasisBankLogin());
        $password       = trim((string)$configuration->getBasisBankPassword());
        $legacyLogin    = trim((string)$configuration->getBasisBankApiToken());
        $legacyPassword = trim((string)$configuration->getBasisBankConsentId());
        $authState      = trim((string)$configuration->getBasisBankAuthState());
        $sessionArtifact = trim((string)$configuration->getBasisBankSessionArtifact());

        if ('' === $login && '' !== $legacyLogin) {
            $login = $legacyLogin;
        }
        if ('' === $password && '' !== $legacyPassword) {
            $password = $legacyPassword;
        }

        BasisSecretManager::saveLogin($login);
        BasisSecretManager::savePassword($password);
        BasisSecretManager::saveAuthState($authState);
        BasisSecretManager::saveSessionArtifact($sessionArtifact);
        BasisSecretManager::saveRequestSmsCode($configuration->isBasisBankRequestSmsCode());
        BasisSecretManager::saveTrustDevice($configuration->isBasisBankTrustDevice());
    }

    private function buildSyncStateFingerprint(string $flow, ?\App\Services\Shared\Configuration\Configuration $configuration): string
    {
        $sharedSecretManager = FireflySecretManager::getBaseUrl();
        if ('basisbank' === $flow) {
            $login             = BasisSecretManager::getLogin($configuration);
            $password          = BasisSecretManager::getPassword($configuration);
            $authState         = BasisSecretManager::getAuthState($configuration);
            $sessionArtifact   = BasisSecretManager::getSessionArtifact($configuration);
            $requestSmsCode    = BasisSecretManager::getRequestSmsCode($configuration);
            $trustDevice       = BasisSecretManager::getTrustDevice($configuration);
            $artifactFingerprint = '' === trim($sessionArtifact) ? '' : hash('sha256', $sessionArtifact);
            $passwordFingerprint = '' === trim($password) ? '' : hash('sha256', $password);
            $manager  = new SyncStateManager();

            return $manager->buildContextFingerprint(
                $flow,
                [
                    config('importer.version'),
                    $login,
                    $passwordFingerprint,
                    $authState,
                    $artifactFingerprint,
                    $requestSmsCode ? 'sms_request' : 'sms_not_requested',
                    $trustDevice ? 'trusted_device' : 'not_trusted_device',
                    $sharedSecretManager,
                ]
            );
        }

        if ('trc20' === $flow) {
            $apiKey  = TRC20SecretManager::getApiKey($configuration);
            $manager = new SyncStateManager();

            return $manager->buildContextFingerprint(
                $flow,
                [config('importer.version'), $apiKey, $sharedSecretManager]
            );
        }

        $sessionId = TBankSecretManager::getSessionId($configuration);
        $cookieHeader = TBankSecretManager::getCookieHeader($configuration);
        $manager = new SyncStateManager();

        return $manager->buildContextFingerprint(
            $flow,
            [config('importer.version'), $sessionId, $cookieHeader, $sharedSecretManager]
        );
    }

    private function releaseSubmissionLock(): void
    {
        if (null === $this->submissionLockKey || '' === trim($this->submissionLockKey)) {
            return;
        }
        cache()->forget($this->submissionLockKey);
        Log::debug(sprintf('Released submission lock "%s".', $this->submissionLockKey));
    }
}
