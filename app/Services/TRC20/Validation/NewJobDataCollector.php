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

        Log::debug(sprintf('TRC20 collectAccounts: apiKey present=%s, wallets count=%d', '' !== $apiKey ? 'yes' : 'no', count($wallets)));

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

        Log::debug('TRC20 collectAccounts: validation passed, fetching wallets from TronGrid...');
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
        $accountMap      = [];
        $newAccounts     = [];
        foreach ($accounts as $account) {
            $serviceAccounts[] = $account;
            $accountId   = is_array($account) ? ($account['id'] ?? '') : ((string)($account->id ?? ''));
            $accountName = is_array($account) ? ($account['name'] ?? '') : ((string)($account->name ?? ''));
            $currency    = is_array($account) ? ($account['currency_code'] ?? ($account['currency'] ?? '')) : ((string)($account->currencyCode ?? ''));
            if ('' !== $accountId) {
                // Pre-populate the configuration accounts map with composite IDs (wallet|SYMBOL => 0).
                // The 0 means "create new Firefly III account during conversion".
                $accountMap[$accountId] = 0;
                $newAccounts[$accountId] = [
                    'create'          => '1',
                    'name'            => $accountName,
                    'type'            => 'asset',
                    'account_role'    => 'defaultAsset',
                    'liability_type'  => 'debt',
                    'liability_direction' => 'debit',
                    'opening_balance' => null,
                    'currency'        => $currency,
                ];
            }
        }
        $this->importJob->setServiceAccounts($serviceAccounts);

        // Set the accounts and new account templates in configuration
        // so the conversion step knows which wallets to fetch and how to create them.
        $configuration = $this->importJob->getConfiguration();
        $configuration->setAccounts($accountMap);
        $configuration->setNewAccounts($newAccounts);
        $this->importJob->setConfiguration($configuration);

        $this->repository->saveToDisk($this->importJob);
        Log::debug(sprintf('TRC20 collectAccounts: set %d accounts in configuration: %s', count($accountMap), json_encode(array_keys($accountMap))));

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
