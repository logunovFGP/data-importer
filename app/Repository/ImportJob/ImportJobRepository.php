<?php

declare(strict_types=1);
/*
 * ImportJobRepository.php
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

namespace App\Repository\ImportJob;

use App\Exceptions\ImporterErrorException;
use App\Models\ImportJob;
use App\Services\BasisBank\Authentication\SecretManager as BasisBankSecretManager;
use App\Services\TBank\Authentication\SecretManager as TBankSecretManager;
use App\Services\TRC20\Authentication\SecretManager as TRC20SecretManager;
use App\Services\CSV\Mapper\TransactionCurrencies;
use App\Services\LunchFlow\Validation\NewJobDataCollector as LunchFlowNewJobDataCollector;
use App\Services\Nordigen\Validation\NewJobDataCollector as NordigenNewJobDataCollector;
use App\Services\BasisBank\Validation\NewJobDataCollector as BasisBankNewJobDataCollector;
use App\Services\TRC20\Validation\NewJobDataCollector as TRC20NewJobDataCollector;
use App\Services\Session\Constants;
use App\Services\Shared\Authentication\SecretManager;
use App\Services\Shared\Configuration\Configuration;
use App\Services\Shared\File\FileContentSherlock;
use App\Services\SimpleFIN\Validation\NewJobDataCollector as SimpleFINNewJobDataCollector;
use App\Services\TBank\Validation\NewJobDataCollector as TBankNewJobDataCollector;
use Exception;
use GrumpyDictator\FFIIIApiSupport\Exceptions\ApiHttpException;
use GrumpyDictator\FFIIIApiSupport\Model\Account;
use GrumpyDictator\FFIIIApiSupport\Request\GetAccountsRequest;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\MessageBag;
use Throwable;

class ImportJobRepository
{
    public function create(): ImportJob
    {
        $importJob = ImportJob::createNew();

        // save to disk:
        $this->saveToDisk($importJob);
        Log::debug(sprintf('Created new import job with key "%s"', $importJob->identifier));

        return $importJob;
    }

    public function deleteImportJob(ImportJob $importJob): void
    {
        $disk = $this->getDisk();
        $file = sprintf('%s.json', $importJob->identifier);
        if (!$disk->exists($file)) {
            return;
        }
        Log::warning(sprintf('Deleted import job with key "%s"', $importJob->identifier));
        $disk->delete($file);
    }

    public function find(string $identifier): ImportJob
    {
        $disk    = $this->getDisk();
        $file    = sprintf('%s.json', $identifier);
        if (!$disk->exists($file)) {
            throw new ImporterErrorException(sprintf('There is no import job with identifier "%s".', $identifier));
        }
        $attempts = 5;
        $sleepUs  = 75_000;
        for ($attempt = 1; $attempt <= $attempts; ++$attempt) {
            $content = $disk->get($file);
            $trimmed = trim((string)$content);
            if ('' === $trimmed) {
                if ($attempt < $attempts) {
                    Log::warning(sprintf('Import job "%s" file is temporarily empty while reading (attempt %d/%d). Retrying.', $identifier, $attempt, $attempts));
                    usleep($sleepUs);

                    continue;
                }
                throw new ImporterErrorException(sprintf('The file for import job "%s" is empty.', $identifier));
            }
            if (!json_validate($trimmed)) {
                if ($attempt < $attempts) {
                    Log::warning(sprintf('Import job "%s" file contains incomplete JSON while reading (attempt %d/%d). Retrying.', $identifier, $attempt, $attempts));
                    usleep($sleepUs);

                    continue;
                }
                throw new ImporterErrorException(sprintf('The file for import job "%s" is invalid JSON.', $identifier));
            }

            try {
                $importJob = ImportJob::createFromJson($trimmed);
                $this->restoreProviderAuthIfNeeded($importJob);

                return $importJob;
            } catch (Throwable $e) {
                if ($attempt < $attempts) {
                    Log::warning(sprintf('Could not deserialize import job "%s" (attempt %d/%d): %s. Retrying.', $identifier, $attempt, $attempts, $e->getMessage()));
                    usleep($sleepUs);

                    continue;
                }
                throw $e;
            }
        }

        throw new ImporterErrorException(sprintf('Could not load import job "%s" after retries.', $identifier));
    }

    public function saveToDisk(ImportJob $importJob): void
    {
        $disk = $this->getDisk();
        $path = sprintf('%s.json', $importJob->identifier);
        if ($disk->exists($path)) {
            $content = trim((string)$disk->get($path));
            if ('' !== $content) {
                $valid = json_validate($content);
                if ($valid) {
                    $json               = json_decode($content, true);
                    $oldInstanceCounter = $json['instance_counter'];
                    $newInstanceCounter = $importJob->getInstanceCounter();
                    if ($oldInstanceCounter > $newInstanceCounter) {
                        throw new ImporterErrorException(sprintf('Cowardly refuse to overwrite current (#%d) import job file with given (#%d).', $oldInstanceCounter, $newInstanceCounter));
                    }
                }
            }
        }
        Log::debug(sprintf('Saved import job with key "%s" to disk.', $importJob->identifier));
        $disk->put($path, $importJob->toString());
    }

    public function markAs(ImportJob $importJob, string $state): ImportJob
    {
        $importJob->setState($state);
        $this->saveToDisk($importJob);

        return $importJob;
    }

    /**
     * FIXME: this is starting to look like a "catch-all" function for all tiny details that need to be taken care of when a new import is started.
     *
     * @throws ApiHttpException
     */
    public function parseImportJob(ImportJob $importJob): MessageBag
    {
        Log::debug(sprintf('Now in parseImportJob("%s")', $importJob->identifier));
        if (true === $importJob->isInitialized()) {
            Log::debug('Import job is already initialized, do nothing.');

            return new MessageBag();
        }
        $messageBag    = new MessageBag();
        $configuration = $importJob->getConfiguration();
        if (null !== $configuration && '' === trim($configuration->getAccessToken())) {
            $sharedAccessToken = trim(SecretManager::getAccessToken());
            if ('' !== $sharedAccessToken) {
                $configuration->setAccessToken($sharedAccessToken);
                Log::debug('Hydrated import job configuration with shared access token.');
            }
        }

        // collect Firefly III accounts, if not already in place for this job.
        // this function returns an array with keys 'assets' and 'liabilities', each containing an array of Firefly III accounts.

        $allAccounts   = $importJob->getApplicationAccounts();
        $count         = count($allAccounts[Constants::ASSET_ACCOUNTS] ?? []) + count($allAccounts[Constants::LIABILITIES] ?? []);
        if (0 === $count) {
            Log::debug('No asset accounts or liabilities found, will collect them now.');
            $applicationAccounts = $this->getApplicationAccounts();
            $importJob->setApplicationAccounts($applicationAccounts);
        }
        if (0 === count($importJob->getCurrencies())) {
            $currencies = $this->getCurrencies();
            $importJob->setCurrencies($currencies);
        }


        Log::debug(sprintf('Now in flow("%s")', $importJob->getFlow()));

        // FIXME this routine must also be followed when doing uploads and POST and what not.
        // FIXME aka this should be part of the routine.
        // validate stuff (from simplefin etc).
        switch ($importJob->getFlow()) {
            case 'file':
                // do file content sherlock things.
                $detector      = new FileContentSherlock();
                $content       = $importJob->getImportableFileString($configuration->isConversion());
                $fileType      = $detector->detectContentTypeFromContent($content);
                $configuration->setContentType($fileType);
                if ('camt' === $fileType) {
                    $camtType = $detector->getCamtType();
                    $configuration->setCamtType($camtType);
                }

                break;

            case 'lunchflow':
                $validator     = new LunchFlowNewJobDataCollector();
                $validator->setImportJob($importJob);
                $messageBag    = $validator->collectAccounts();
                // get import job + configuration back:
                $importJob     = $validator->getImportJob();
                $configuration = $importJob->getConfiguration();
                $configuration->setDuplicateDetectionMethod('cell');

                break;

            case 'basisbank':
                $configuration = $this->hydrateBasisBankContext($configuration);
                $validator     = new BasisBankNewJobDataCollector();
                $validator->setImportJob($importJob);
                $messageBag    = $validator->collectAccounts();
                $importJob     = $validator->getImportJob();
                $configuration = $importJob->getConfiguration();
                $configuration->setDuplicateDetectionMethod('cell');

                break;

            case 'trc20':
                $validator     = new TRC20NewJobDataCollector();
                $validator->setImportJob($importJob);
                $messageBag    = $validator->collectAccounts();
                $importJob     = $validator->getImportJob();
                $configuration = $importJob->getConfiguration();
                $configuration->setDuplicateDetectionMethod('cell');

                break;

            case 'tbank':
                $validator     = new TBankNewJobDataCollector();
                $validator->setImportJob($importJob);
                $messageBag    = $validator->collectAccounts();
                $importJob     = $validator->getImportJob();
                $configuration = $importJob->getConfiguration();
                $configuration->setDuplicateDetectionMethod('cell');

                break;

            case 'simplefin':
                $validator     = new SimpleFINNewJobDataCollector();
                $validator->setImportJob($importJob);
                $messageBag    = $validator->collectAccounts();
                // get import job + configuration back:
                $importJob     = $validator->getImportJob();
                $configuration = $importJob->getConfiguration();
                $configuration->setDuplicateDetectionMethod('cell');

                break;

            case 'nordigen':
                // nordigen, download list of accounts.
                $validator     = new NordigenNewJobDataCollector();
                $validator->setImportJob($importJob);
                $messageBag    = $validator->collectAccounts();
                // get import job + configuration back:
                $importJob     = $validator->getImportJob();
                $configuration = $importJob->getConfiguration();
                $configuration->setDuplicateDetectionMethod('cell');

                break;

            case 'sophtron':
                // get import job + configuration back:
                $configuration = $importJob->getConfiguration();
                $configuration->setDuplicateDetectionMethod('cell');

                break;

            default:
                $messageBag->add('importable_file', sprintf('Cannot yet process import flow "%s"', $importJob->getFlow()));
                $messageBag->add('config_file', sprintf('Cannot yet process import flow "%s"', $importJob->getFlow()));
        }

        // save configuration and return it.
        if (0 === count($messageBag)) {
            $importJob->setState('is_parsed');
            $importJob->setInitialized(true);
        }
        $importJob     = $this->setConfiguration($importJob, $configuration);
        $this->saveToDisk($importJob);

        // if parse errors, display to user with a redirect to upload?
        return $messageBag;
    }

    public static function convertString(string $content): string
    {
        $encoding = mb_detect_encoding($content, config('importer.encoding'), true);
        if (false === $encoding) {
            Log::warning('Tried to detect encoding but could not find valid encoding. Assume UTF-8.');

            return $content;
        }
        if ('ASCII' === $encoding || 'UTF-8' === $encoding) {
            return $content;
        }
        Log::warning(sprintf('Content is detected as "%s" and will be converted to UTF-8. Your milage may vary.', $encoding));

        return mb_convert_encoding($content, 'UTF-8', $encoding);
    }

    public function setConfigurationString(ImportJob $importJob, string $configFileContent): ImportJob
    {
        $importJob->setConfigurationString($configFileContent);
        $this->saveToDisk($importJob);

        return $importJob;
    }

    public function setFlow(ImportJob $importJob, string $flow): ImportJob
    {
        $importJob->setFlow($flow);
        $this->saveToDisk($importJob);

        return $importJob;
    }

    public function setImportableFileString(ImportJob $importJob, string $importableFileContent)
    {
        $importJob->setImportableFileString($importableFileContent);
        $this->saveToDisk($importJob);

        return $importJob;
    }

    private function getDisk(): Filesystem|LocalFilesystemAdapter
    {
        return Storage::disk('import-jobs');
    }

    private function setConfiguration(ImportJob $importJob, Configuration $configuration): ImportJob
    {
        $importJob->setConfiguration($configuration);
        $this->saveToDisk($importJob);

        return $importJob;
    }

    /**
     * @throws ApiHttpException
     */
    private function getApplicationAccounts(): array
    {
        Log::debug(sprintf('Now in %s', __METHOD__));
        $accounts = [
            Constants::ASSET_ACCOUNTS => [],
            Constants::LIABILITIES    => [],
        ];
        $url      = null;

        try {
            $url           = SecretManager::getBaseUrl();
            $token         = SecretManager::getAccessToken();

            if ('' === $url || '' === $token) {
                Log::error('Base URL or Access Token is empty. Cannot fetch accounts.', ['url_empty' => '' === $url, 'token_empty' => '' === $token]);

                return $accounts; // Return empty accounts if auth details are missing
            }

            // Fetch ASSET accounts
            Log::debug('Fetching asset accounts from Firefly III.', ['url' => $url]);
            $requestAsset  = new GetAccountsRequest($url, $token);
            $requestAsset->setType(GetAccountsRequest::ASSET);
            $requestAsset->setVerify(config('importer.connection.verify'));
            $requestAsset->setTimeOut(config('importer.connection.timeout'));
            $responseAsset = $requestAsset->get();

            /** @var Account $account */
            foreach ($responseAsset as $account) {
                // Log::debug(sprintf('Class of account is %s', get_class($account)));
                $accounts[Constants::ASSET_ACCOUNTS][$account->id] = $account;
            }
            Log::debug(sprintf('Fetched %d asset accounts.', count($accounts[Constants::ASSET_ACCOUNTS])));
        } catch (ApiHttpException|Exception $e) {
            Log::error(sprintf('%s while fetching Firefly III asset accounts.', get_class($e)), [
                'message' => $e->getMessage(),
                'code'    => $e->getCode(),
                'url'     => $url, // Log URL that might have caused issue
                'trace'   => $e->getTraceAsString(),
            ]);

        }

        try {
            Log::debug('Fetching liability accounts from Firefly III.', ['url' => $url]);
            $requestLiability  = new GetAccountsRequest($url, $token);
            $requestLiability->setVerify(config('importer.connection.verify'));
            $requestLiability->setTimeOut(config('importer.connection.timeout'));
            $requestLiability->setType(GetAccountsRequest::LIABILITIES);
            $responseLiability = $requestLiability->get();

            /** @var Account $account */
            foreach ($responseLiability as $account) {
                $accounts[Constants::LIABILITIES][$account->id] = $account;
            }
            Log::debug(sprintf('Fetched %d liability accounts.', count($accounts[Constants::LIABILITIES])));

        } catch (ApiHttpException|Exception $e) {
            Log::error(sprintf('%s while fetching Firefly III liability accounts.', get_class($e)), [
                'message' => $e->getMessage(),
                'code'    => $e->getCode(),
                'url'     => $url,
                'trace'   => $e->getTraceAsString(),
            ]);
        }
        // Log::debug('CollectsAccounts::getFireflyIIIAccounts - Returning accounts structure.', $accounts);

        return $accounts;
    }

    private function getCurrencies(): array
    {
        try {
            /** @var TransactionCurrencies $mapper */
            $mapper = app(TransactionCurrencies::class);

            return $mapper->getMap();
        } catch (Exception $e) {
            Log::error(sprintf('Failed to load currencies: %s', $e->getMessage()));

            return [];
        }
    }

    private function hydrateBasisBankContext(Configuration $configuration): Configuration
    {
        $login          = BasisBankSecretManager::getLogin($configuration);
        $password       = BasisBankSecretManager::getPassword($configuration);
        $legacyApiToken = trim((string)$configuration->getBasisBankApiToken());
        $legacyConsent  = trim((string)$configuration->getBasisBankConsentId());

        if ('' === trim($login) && '' !== $legacyApiToken) {
            $login = $legacyApiToken;
        }
        if ('' === trim($password) && '' !== $legacyConsent) {
            $password = $legacyConsent;
        }

        $configuration->setBasisBankLogin($login);
        $configuration->setBasisBankPassword($password);
        $configuration->setBasisBankAuthState(BasisBankSecretManager::getAuthState($configuration));
        $configuration->setBasisBankSessionArtifact(BasisBankSecretManager::getSessionArtifact($configuration));
        $configuration->setBasisBankRequestSmsCode(BasisBankSecretManager::getRequestSmsCode($configuration));
        $configuration->setBasisBankTrustDevice(BasisBankSecretManager::getTrustDevice($configuration));

        return $configuration;
    }

    /**
     * Restore provider auth from the import job JSON into the session if the session
     * has lost the provider auth data (e.g., after container restart or session expiry).
     * This is the "resume" mechanism for provider auth persistence.
     */
    private function restoreProviderAuthIfNeeded(ImportJob $importJob): void
    {
        $providerAuth = $importJob->getProviderAuth();
        if ([] === $providerAuth) {
            return;
        }

        $provider = (string)($providerAuth['provider'] ?? '');
        if ('' === $provider) {
            return;
        }

        match ($provider) {
            'basisbank' => $this->restoreBasisBankAuth($providerAuth),
            'tbank'     => $this->restoreTBankAuth($providerAuth),
            'trc20'     => $this->restoreTRC20Auth($providerAuth),
            default     => Log::debug(sprintf('No provider auth restore handler for provider "%s".', $provider)),
        };
    }

    private function restoreBasisBankAuth(array $auth): void
    {
        // Only restore if the session has lost the auth state
        $currentAuthState = (string)session()->get(BasisBankSecretManager::AUTH_STATE, '');
        if ('' !== $currentAuthState) {
            return;
        }

        $sessionArtifact = (string)($auth['session_artifact'] ?? '');
        $authState       = (string)($auth['auth_state'] ?? '');
        $login           = (string)($auth['login'] ?? '');
        $password        = (string)($auth['password'] ?? '');
        $trustDevice     = (bool)($auth['trust_device'] ?? false);

        if ('' === $authState) {
            return;
        }

        Log::info('Restoring BasisBank provider auth from import job JSON into session.');
        if ('' !== $login) {
            BasisBankSecretManager::saveLogin($login);
        }
        if ('' !== $password) {
            BasisBankSecretManager::savePassword($password);
        }
        BasisBankSecretManager::saveAuthState($authState);
        if ('' !== $sessionArtifact) {
            BasisBankSecretManager::saveSessionArtifact($sessionArtifact);
        }
        BasisBankSecretManager::saveTrustDevice($trustDevice);
    }

    private function restoreTBankAuth(array $auth): void
    {
        $currentAuthState = (string)session()->get(TBankSecretManager::AUTH_STATE, '');
        if ('' !== $currentAuthState) {
            return;
        }

        $sessionId    = (string)($auth['session_id'] ?? '');
        $cookieHeader = (string)($auth['cookie_header'] ?? '');
        $accessLevel  = (string)($auth['access_level'] ?? '');
        $authState    = (string)($auth['auth_state'] ?? '');
        $devicePin    = (string)($auth['device_pin'] ?? '');

        if ('' === $authState) {
            return;
        }

        Log::info('Restoring TBank provider auth from import job JSON into session.');
        if ('' !== $sessionId) {
            TBankSecretManager::saveSessionId($sessionId);
        }
        if ('' !== $cookieHeader) {
            TBankSecretManager::saveCookieHeader($cookieHeader);
        }
        if ('' !== $accessLevel) {
            TBankSecretManager::saveAccessLevel($accessLevel);
        }
        TBankSecretManager::saveAuthState($authState);
        if ('' !== $devicePin) {
            TBankSecretManager::saveDevicePin($devicePin);
        }
    }

    private function restoreTRC20Auth(array $auth): void
    {
        $currentApiKey = (string)session()->get(TRC20SecretManager::API_KEY, '');
        if ('' !== $currentApiKey) {
            return;
        }

        $apiKey  = (string)($auth['api_key'] ?? '');
        $wallets = (string)($auth['wallets'] ?? '');

        if ('' === $apiKey && '' === $wallets) {
            return;
        }

        Log::info('Restoring TRC20 provider auth from import job JSON into session.');
        if ('' !== $apiKey) {
            TRC20SecretManager::saveApiKey($apiKey);
        }
        if ('' !== $wallets) {
            TRC20SecretManager::saveWallets($wallets);
        }
    }
}
