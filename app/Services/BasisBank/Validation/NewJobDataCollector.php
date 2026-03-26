<?php

declare(strict_types=1);

namespace App\Services\BasisBank\Validation;

use App\Exceptions\ImporterHttpException;
use App\Models\ImportJob;
use App\Repository\ImportJob\ImportJobRepository;
use App\Services\BasisBank\Auth\BasisBankSessionState;
use App\Services\BasisBank\Authentication\SecretManager;
use App\Services\BasisBank\Request\GetAccountsRequest;
use App\Services\Shared\Configuration\Configuration;
use App\Services\Shared\Validation\NewJobDataCollectorInterface;
use Illuminate\Support\MessageBag;

class NewJobDataCollector implements NewJobDataCollectorInterface
{
    private ImportJob $importJob;
    private ImportJobRepository $repository;

    public function __construct()
    {
        $this->repository = new ImportJobRepository();
    }

    public function getFlowName(): string
    {
        return 'basisbank';
    }

    public function getImportJob(): ImportJob
    {
        return $this->importJob;
    }

    public function setImportJob(ImportJob $importJob): void
    {
        $importJob->refreshInstanceIdentifier();
        $this->importJob = $importJob;
    }

    public function validate(): MessageBag
    {
        $errors        = new MessageBag();
        $configuration = $this->importJob->getConfiguration();
        $token         = SecretManager::getApiToken($configuration);
        $consentId     = SecretManager::getConsentId($configuration);
        $authState     = SecretManager::getAuthState($configuration);
        $artifact      = SecretManager::getSessionArtifact($configuration);

        if (!$this->hasBasisBankAuthContext($token, $consentId, $authState, $artifact)) {
            $errors->add('basisbank_api_token', 'BasisBank API token and Consent-ID are required.');
            $errors->add('basisbank_consent_id', 'BasisBank API token and Consent-ID are required.');
        }

        return $errors;
    }

    public function collectAccounts(): MessageBag
    {
        $configuration = $this->importJob->getConfiguration();
        $token         = SecretManager::getApiToken($configuration);
        $consentId     = SecretManager::getConsentId($configuration);
        $authState     = SecretManager::getAuthState($configuration);
        $artifact      = SecretManager::getSessionArtifact($configuration);
        $errors        = new MessageBag();

        if (!$this->hasBasisBankAuthContext($token, $consentId, $authState, $artifact)) {
            $errors->add('basisbank_api_token', 'BasisBank API token and Consent-ID are required.');
            $errors->add('basisbank_consent_id', 'BasisBank API token and Consent-ID are required.');

            return $errors;
        }

        $configuration           = $this->hydrateBasisBankContext($configuration);
        $request                 = new GetAccountsRequest($token, $consentId, $artifact);
        $this->importJob->setConfiguration($configuration);
        $this->repository->saveToDisk($this->importJob);
        $request->setTimeOut((float)config('importer.connection.timeout'));

        try {
            $accounts = $request->get();
        } catch (ImporterHttpException $e) {
            $errors->add('connection', sprintf('Failed to fetch BasisBank accounts: %s', $e->getMessage()));

            return $errors;
        }

        $serviceAccounts = [];
        foreach ($accounts as $account) {
            $serviceAccounts[] = $account;
        }
        $this->importJob->setServiceAccounts($serviceAccounts);
        $this->repository->saveToDisk($this->importJob);

        return $errors;
    }

    private function hydrateBasisBankContext(Configuration $configuration): Configuration
    {
        $configuration->setBasisBankLogin(SecretManager::getLogin($configuration));
        $configuration->setBasisBankPassword(SecretManager::getPassword($configuration));
        $configuration->setBasisBankAuthState(SecretManager::getAuthState($configuration));
        $configuration->setBasisBankSessionArtifact(SecretManager::getSessionArtifact($configuration));
        $configuration->setBasisBankRequestSmsCode(SecretManager::getRequestSmsCode($configuration));
        $configuration->setBasisBankTrustDevice(SecretManager::getTrustDevice($configuration));

        return $configuration;
    }

    private function hasBasisBankAuthContext(string $token, string $consentId, string $authState, string $artifact): bool
    {
        if ('' !== trim($token) && '' !== trim($consentId)) {
            return true;
        }

        return BasisBankSessionState::AUTHENTICATED === $authState && '' !== trim($artifact);
    }
}
