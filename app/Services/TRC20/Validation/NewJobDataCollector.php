<?php

declare(strict_types=1);

namespace App\Services\TRC20\Validation;

use App\Exceptions\ImporterHttpException;
use App\Models\ImportJob;
use App\Repository\ImportJob\ImportJobRepository;
use App\Services\Shared\Validation\NewJobDataCollectorInterface;
use App\Services\TRC20\Authentication\SecretManager;
use App\Services\TRC20\Request\GetWalletsRequest;
use App\Services\TRC20\Support\TRC20AddressValidator;
use Illuminate\Support\Facades\Log;
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
        return 'trc20';
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
        $apiKey        = SecretManager::getApiKey($configuration);
        $wallets       = SecretManager::getWallets($configuration);

        if ('' === trim($apiKey)) {
            $errors->add('trc20_api_key', 'TRC20 API key is required.');
        }
        if (0 === count($wallets)) {
            $errors->add('trc20_wallets', 'At least one TRC20 wallet is required.');
        }
        if ('' !== trim($apiKey) && (preg_match('/\s/', $apiKey) === 1 || strlen(trim($apiKey)) < 16)) {
            $errors->add('trc20_api_key', 'TRC20 API key format is invalid.');
        }

        $invalidWallets = $this->findInvalidWallets($wallets);
        if (0 !== count($invalidWallets)) {
            $errors->add('trc20_wallets', sprintf('Invalid TRC20 wallet format: %s', implode(', ', $invalidWallets)));
        }

        return $errors;
    }

    public function collectAccounts(): MessageBag
    {
        $configuration = $this->importJob->getConfiguration();
        $apiKey        = SecretManager::getApiKey($configuration);
        $wallets       = SecretManager::getWallets($configuration);
        $errors        = new MessageBag();

        Log::debug(sprintf('TRC20 collectAccounts: apiKey length=%d, wallets count=%d, wallets=%s', strlen($apiKey), count($wallets), json_encode($wallets)));

        if ('' === trim($apiKey)) {
            Log::warning('TRC20 collectAccounts: REJECTED — API key is empty');
            $errors->add('trc20_api_key', 'TRC20 API key is required.');
            return $errors;
        }
        if (0 === count($wallets)) {
            Log::warning('TRC20 collectAccounts: REJECTED — no wallets');
            $errors->add('trc20_wallets', 'At least one TRC20 wallet is required.');
            return $errors;
        }
        if (preg_match('/\s/', $apiKey) === 1 || strlen(trim($apiKey)) < 16) {
            Log::warning(sprintf('TRC20 collectAccounts: REJECTED — API key format invalid (len=%d, has_ws=%s)', strlen(trim($apiKey)), preg_match('/\s/', $apiKey) === 1 ? 'yes' : 'no'));
            $errors->add('trc20_api_key', 'TRC20 API key format is invalid.');

            return $errors;
        }
        $invalidWallets = $this->findInvalidWallets($wallets);
        if (0 !== count($invalidWallets)) {
            Log::warning(sprintf('TRC20 collectAccounts: REJECTED — invalid wallets: %s', implode(', ', $invalidWallets)));
            $errors->add('trc20_wallets', sprintf('Invalid TRC20 wallet format: %s', implode(', ', $invalidWallets)));

            return $errors;
        }

        Log::debug('TRC20 collectAccounts: validation passed, fetching wallets from Tronscan...');
        $request = new GetWalletsRequest($apiKey, $wallets);
        $request->setTimeOut((float)config('importer.connection.timeout'));

        try {
            $accounts = $request->get();
        } catch (ImporterHttpException $e) {
            Log::error(sprintf('TRC20 collectAccounts: HTTP FAILED — %s', $e->getMessage()));
            $errors->add('connection', sprintf('Failed to fetch TRC20 wallets: %s', $e->getMessage()));
            return $errors;
        }
        Log::debug(sprintf('TRC20 collectAccounts: SUCCESS — fetched %d accounts', count($accounts)));

        $serviceAccounts = [];
        foreach ($accounts as $account) {
            $serviceAccounts[] = $account;
        }
        $this->importJob->setServiceAccounts($serviceAccounts);
        $this->repository->saveToDisk($this->importJob);

        return $errors;
    }

    private function findInvalidWallets(array $wallets): array
    {
        $invalid = [];
        foreach ($wallets as $wallet) {
            $value = trim((string)$wallet);
            if ('' === $value) {
                continue;
            }

            if (!TRC20AddressValidator::isValid($value)) {
                $invalid[] = $value;
            }
        }

        return array_values(array_unique($invalid));
    }
}
