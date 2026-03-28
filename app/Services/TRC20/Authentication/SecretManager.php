<?php

declare(strict_types=1);

namespace App\Services\TRC20\Authentication;

use App\Services\Shared\Configuration\Configuration;
use App\Services\Shared\Secrets\ProviderSecretStore;
use Illuminate\Support\Facades\Log;
use Throwable;

class SecretManager
{
    public const string API_KEY = 'trc20_api_key';
    public const string WALLETS = 'trc20_wallets';
    private const string PROVIDER = 'trc20';

    private const string WALLET_SEPARATOR_PATTERN = '/[\r\n,]++/';

    public static function getApiKey(?Configuration $configuration = null): string
    {
        $sessionKey = (string)session()->get(self::API_KEY, '');
        if ('' !== $sessionKey) {
            return $sessionKey;
        }

        $storedKey = self::getStoredString(self::API_KEY);
        if ('' !== $storedKey) {
            return $storedKey;
        }

        $configKey = (string)config('trc20.api_key', '');
        if ('' !== $configKey) {
            return $configKey;
        }

        return (string)$configuration?->getTrc20ApiKey();
    }

    public static function getWallets(?Configuration $configuration = null): array
    {
        $sessionWallets = (string)session()->get(self::WALLETS, '');
        if ('' !== $sessionWallets) {
            return self::normalizeWallets($sessionWallets);
        }

        $storedWallets = self::getStoredString(self::WALLETS);
        if ('' !== $storedWallets) {
            return self::normalizeWallets($storedWallets);
        }

        $configWallets = (string)config('trc20.wallets', '');
        if ('' !== $configWallets) {
            return self::normalizeWallets($configWallets);
        }

        $configWalletsFromConfiguration = (string)$configuration?->getTrc20Wallets();

        return self::normalizeWallets($configWalletsFromConfiguration);
    }

    public static function saveApiKey(string $apiKey): void
    {
        $value = trim($apiKey);
        session()->put(self::API_KEY, $value);
        self::saveToStore(self::API_KEY, $value);
    }

    public static function saveWallets(string $wallets): void
    {
        $value = trim($wallets);
        session()->put(self::WALLETS, $value);
        self::saveToStore(self::WALLETS, $value);
    }

    public static function clearSavedCredentials(): void
    {
        session()->forget([self::API_KEY, self::WALLETS]);
        try {
            self::store()->forgetProvider(self::PROVIDER);
        } catch (Throwable $e) {
            Log::warning(sprintf('Could not clear TRC-20 persisted secrets: %s', $e->getMessage()));
        }
    }

    public static function normalizeWallets(string $wallets): array
    {
        $wallets = trim($wallets);
        if ('' === $wallets) {
            return [];
        }

        $items = preg_split(self::WALLET_SEPARATOR_PATTERN, $wallets) ?: [];
        $normalized = [];
        foreach ($items as $item) {
            $value = trim((string)$item);
            if ('' === $value) {
                continue;
            }
            $normalized[$value] = $value;
        }

        return array_values($normalized);
    }

    private static function store(): ProviderSecretStore
    {
        return app(ProviderSecretStore::class);
    }

    private static function getStoredString(string $key): string
    {
        $value = self::store()->get(self::PROVIDER, $key, '');
        if (!is_scalar($value)) {
            return '';
        }

        return trim((string)$value);
    }

    private static function saveToStore(string $key, mixed $value): void
    {
        try {
            self::store()->put(self::PROVIDER, $key, $value);
        } catch (Throwable $e) {
            Log::warning(sprintf('Could not persist TRC-20 secret "%s": %s', $key, $e->getMessage()));
        }
    }
}
