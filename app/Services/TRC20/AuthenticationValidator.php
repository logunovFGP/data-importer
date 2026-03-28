<?php

declare(strict_types=1);

namespace App\Services\TRC20;

use App\Services\Enums\AuthenticationStatus;
use App\Services\Shared\Authentication\AuthenticationValidatorInterface;
use App\Services\TRC20\Authentication\SecretManager;
use App\Services\TRC20\Support\TRC20AddressValidator;
use Illuminate\Support\Facades\Log;

class AuthenticationValidator implements AuthenticationValidatorInterface
{
    public function validate(): AuthenticationStatus
    {
        $apiKey = SecretManager::getApiKey();
        if ('' === $apiKey) {
            return AuthenticationStatus::NODATA;
        }

        $wallets = SecretManager::getWallets();
        if (0 === count($wallets)) {
            return AuthenticationStatus::NODATA;
        }

        foreach ($wallets as $wallet) {
            if (! TRC20AddressValidator::isValid($wallet)) {
                Log::warning(sprintf('TRC20 auth validation rejected invalid wallet address: %s', $wallet));

                return AuthenticationStatus::ERROR;
            }
        }

        return AuthenticationStatus::AUTHENTICATED;
    }

    public function getData(): array
    {
        return [
            'api_key' => SecretManager::getApiKey(),
            'wallets' => implode(PHP_EOL, SecretManager::getWallets()),
        ];
    }

    public function setData(array $data): void
    {
        SecretManager::saveApiKey((string)($data['api_key'] ?? ''));
        SecretManager::saveWallets((string)($data['wallets'] ?? ''));
    }

}
