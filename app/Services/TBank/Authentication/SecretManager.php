<?php

declare(strict_types=1);

namespace App\Services\TBank\Authentication;

use App\Services\Shared\Secrets\ProviderSecretStore;
use App\Services\Shared\Configuration\Configuration;
use Illuminate\Support\Facades\Log;
use Throwable;

class SecretManager
{
    public const string API_TOKEN = 'tbank_api_token';
    public const string SESSION_ID = 'tbank_session_id';
    public const string COOKIE_HEADER = 'tbank_cookie_header';
    public const string ACCESS_LEVEL = 'tbank_access_level';
    public const string AUTH_STATE = 'tbank_auth_state';
    public const string DEVICE_PIN = 'tbank_device_pin';
    public const string OAUTH_STATE = 'tbank_oauth_state';
    private const string LEGACY_LOGIN = 'tbank_login';
    private const string PROVIDER = 'tbank';

    public static function getApiToken(?Configuration $configuration = null): string
    {
        return self::getSessionId($configuration);
    }

    public static function saveApiToken(string $token): void
    {
        self::saveSessionId($token);
    }

    public static function getSessionId(?Configuration $configuration = null): string
    {
        $sessionToken = trim((string)session()->get(self::SESSION_ID, ''));
        if ('' !== $sessionToken) {
            return $sessionToken;
        }
        $legacySessionToken = trim((string)session()->get(self::API_TOKEN, ''));
        if ('' !== $legacySessionToken) {
            return $legacySessionToken;
        }
        $legacySessionLogin = trim((string)session()->get(self::LEGACY_LOGIN, ''));
        if ('' !== $legacySessionLogin) {
            return $legacySessionLogin;
        }

        $storedSessionId = self::getStoredString(self::SESSION_ID);
        if ('' !== $storedSessionId) {
            return $storedSessionId;
        }
        $storedApiToken = self::getStoredString(self::API_TOKEN);
        if ('' !== $storedApiToken) {
            return $storedApiToken;
        }
        $storedLogin = self::getStoredString('login');
        if ('' !== $storedLogin) {
            return $storedLogin;
        }
        $configToken = trim((string)config('tbank.api_token'));
        if ('' !== $configToken) {
            return $configToken;
        }
        $configSessionId = trim((string)config('tbank.session_id'));
        if ('' !== $configSessionId) {
            return $configSessionId;
        }
        $legacyConfigToken = trim((string)config('tbank.login'));
        if ('' !== $legacyConfigToken) {
            return $legacyConfigToken;
        }
        $cookieSessionId = self::extractSessionIdFromCookieHeader(self::getCookieHeader($configuration));
        if ('' !== $cookieSessionId) {
            return $cookieSessionId;
        }

        return trim((string)$configuration?->getTBankApiToken());
    }

    public static function saveSessionId(string $sessionId): void
    {
        $value = trim($sessionId);
        session()->put(self::SESSION_ID, $value);
        session()->put(self::API_TOKEN, $value);
        session()->put(self::LEGACY_LOGIN, $value);
        self::saveToStore(self::SESSION_ID, $value);
        self::saveToStore(self::API_TOKEN, $value);
        self::saveToStore('login', $value);
    }

    public static function getCookieHeader(?Configuration $configuration = null): string
    {
        $sessionValue = trim((string)session()->get(self::COOKIE_HEADER, ''));
        if ('' !== $sessionValue) {
            return $sessionValue;
        }

        $stored = self::getStoredString(self::COOKIE_HEADER);
        if ('' !== $stored) {
            return $stored;
        }

        $configValue = trim((string)config('tbank.cookie_header'));
        if ('' !== $configValue) {
            return $configValue;
        }

        if (null !== $configuration && method_exists($configuration, 'getTBankCookieHeader')) {
            return trim((string)$configuration->getTBankCookieHeader());
        }

        return '';
    }

    public static function saveCookieHeader(string $cookieHeader): void
    {
        $normalized = self::normalizeCookieHeader($cookieHeader);
        session()->put(self::COOKIE_HEADER, $normalized);
        self::saveToStore(self::COOKIE_HEADER, $normalized);
        $cookieSessionId = self::extractSessionIdFromCookieHeader($normalized);
        if ('' !== $cookieSessionId) {
            self::saveSessionId($cookieSessionId);
        }
    }

    public static function getAccessLevel(): string
    {
        $sessionValue = trim((string)session()->get(self::ACCESS_LEVEL, ''));
        if ('' !== $sessionValue) {
            return $sessionValue;
        }

        return self::getStoredString(self::ACCESS_LEVEL);
    }

    public static function saveAccessLevel(string $accessLevel): void
    {
        $value = trim($accessLevel);
        session()->put(self::ACCESS_LEVEL, $value);
        self::saveToStore(self::ACCESS_LEVEL, $value);
    }

    public static function getAuthState(): string
    {
        $sessionValue = trim((string)session()->get(self::AUTH_STATE, ''));
        if ('' !== $sessionValue) {
            return $sessionValue;
        }

        return self::getStoredString(self::AUTH_STATE);
    }

    public static function saveAuthState(string $authState): void
    {
        $value = trim($authState);
        session()->put(self::AUTH_STATE, $value);
        self::saveToStore(self::AUTH_STATE, $value);
    }

    public static function getDevicePin(): string
    {
        $sessionValue = trim((string)session()->get(self::DEVICE_PIN, ''));
        if ('' !== $sessionValue) {
            return $sessionValue;
        }

        return self::getStoredString(self::DEVICE_PIN);
    }

    public static function saveDevicePin(string $devicePin): void
    {
        $value = trim($devicePin);
        session()->put(self::DEVICE_PIN, $value);
        self::saveToStore(self::DEVICE_PIN, $value);
    }

    public static function saveSessionContext(string $sessionId, string $cookieHeader, string $accessLevel = 'CLIENT'): void
    {
        self::saveSessionId($sessionId);
        self::saveCookieHeader($cookieHeader);
        self::saveAccessLevel($accessLevel);
        self::saveAuthState('AUTHENTICATED');
    }

    public static function saveOAuthState(string $state): void
    {
        session()->put(self::OAUTH_STATE, trim($state));
    }

    public static function getOAuthState(): string
    {
        return trim((string)session()->get(self::OAUTH_STATE, ''));
    }

    public static function clearOAuthState(): void
    {
        session()->forget([self::OAUTH_STATE]);
    }

    public static function clearSavedCredentials(): void
    {
        session()->forget(
            [
                self::SESSION_ID,
                self::COOKIE_HEADER,
                self::ACCESS_LEVEL,
                self::AUTH_STATE,
                self::DEVICE_PIN,
                self::API_TOKEN,
                self::LEGACY_LOGIN,
                self::OAUTH_STATE,
            ]
        );
        try {
            self::store()->forgetProvider(self::PROVIDER);
        } catch (Throwable $e) {
            Log::warning(sprintf('Could not clear TBank persisted secrets: %s', $e->getMessage()));
        }
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
            Log::warning(sprintf('Could not persist TBank secret "%s": %s', $key, $e->getMessage()));
        }
    }

    private static function normalizeCookieHeader(string $cookieHeader): string
    {
        $input  = trim($cookieHeader);
        if ('' === $input) {
            return '';
        }
        $parts  = array_filter(array_map('trim', explode(';', $input)));
        $result = [];
        foreach ($parts as $part) {
            if (!str_contains($part, '=')) {
                continue;
            }
            [$name, $value] = explode('=', $part, 2);
            $name = trim($name);
            $value = trim($value);
            if ('' === $name || '' === $value) {
                continue;
            }
            $result[$name] = $value;
        }

        return implode('; ', array_map(static fn (string $name, string $value): string => sprintf('%s=%s', $name, $value), array_keys($result), $result));
    }

    private static function extractSessionIdFromCookieHeader(string $cookieHeader): string
    {
        $pairs = self::cookiePairsFromCookieHeader($cookieHeader);
        foreach (['psid', 'old_session_id', 'sessionid'] as $key) {
            $value = trim((string)($pairs[$key] ?? ''));
            if ('' !== $value) {
                return $value;
            }
        }

        return '';
    }

    private static function cookiePairsFromCookieHeader(string $cookieHeader): array
    {
        $result = [];
        foreach (explode(';', trim($cookieHeader)) as $chunk) {
            $part = trim($chunk);
            if ('' === $part || !str_contains($part, '=')) {
                continue;
            }
            [$name, $value] = explode('=', $part, 2);
            $name = trim($name);
            $value = trim($value);
            if ('' === $name || '' === $value) {
                continue;
            }
            $result[$name] = $value;
        }

        return $result;
    }
}
