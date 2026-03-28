<?php

declare(strict_types=1);

namespace App\Services\TBank\Validation;

use App\Exceptions\ImporterHttpException;
use App\Models\ImportJob;
use App\Repository\ImportJob\ImportJobRepository;
use App\Services\Shared\Validation\NewJobDataCollectorInterface;
use App\Services\TBank\Authentication\SecretManager;
use App\Services\TBank\Request\GetAccountsRequest;
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
        return 'tbank';
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
        $sessionId     = SecretManager::getSessionId($configuration);
        $cookieHeader  = SecretManager::getCookieHeader($configuration);

        if ('' === trim($sessionId) || '' === trim($cookieHeader)) {
            $errors->add('tbank_auth', 'TBank authentication is required. Complete TBank web login first.');
        }

        return $errors;
    }

    public function collectAccounts(): MessageBag
    {
        $configuration = $this->importJob->getConfiguration();
        $sessionId     = SecretManager::getSessionId($configuration);
        $cookieHeader  = SecretManager::getCookieHeader($configuration);
        $errors        = new MessageBag();

        if ('' === trim($sessionId) || '' === trim($cookieHeader)) {
            $errors->add('tbank_auth', 'TBank authentication is required. Complete TBank web login first.');

            return $errors;
        }

        $request = new GetAccountsRequest($sessionId, $cookieHeader);
        $request->setTimeOut((float)config('importer.connection.timeout'));

        try {
            $accounts = $request->get();
        } catch (ImporterHttpException $e) {
            $errors->add('connection', sprintf('Failed to fetch TBank accounts: %s', $e->getMessage()));

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
}
