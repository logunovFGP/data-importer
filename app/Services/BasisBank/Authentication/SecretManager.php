<?php

declare(strict_types=1);

namespace App\Services\BasisBank\Authentication;

use App\Services\Shared\Configuration\Configuration;
use App\Services\Shared\Secrets\ProviderSecretStore;
use Illuminate\Support\Facades\Log;
use Throwable;

class SecretManager
{
    public const string API_TOKEN       = 'basisbank_api_token';
    public const string CONSENT_ID      = 'basisbank_consent_id';
    public const string LOGIN           = 'basisbank_login';
    public const string PASSWORD        = 'basisbank_password';
    public const string OTP_CODE        = 'basisbank_otp_code';
    public const string AUTH_STATE      = 'basisbank_auth_state';
    public const string SESSION_ARTIFACT = 'basisbank_session_artifact';
    public const string REQUEST_SMS_CODE = 'basisbank_request_sms_code';
    public const string TRUST_DEVICE    = 'basisbank_trust_device';
    private const string PROVIDER       = 'basisbank';

    public static function getApiToken(?Configuration $configuration = null): string
    {
        return self::getLogin($configuration);
    }

    public static function getConsentId(?Configuration $configuration = null): string
    {
        return self::getPassword($configuration);
    }

    public static function saveApiToken(string $token): void
    {
        self::saveLogin($token);
    }

    public static function saveConsentId(string $consentId): void
    {
        self::savePassword($consentId);
    }

    public static function getLogin(?Configuration $configuration = null): string
    {
        $sessionLogin = (string)session()->get(self::LOGIN, '');
        if ('' !== $sessionLogin) {
            return $sessionLogin;
        }

        $legacySessionLogin = (string)session()->get(self::API_TOKEN, '');
        if ('' !== $legacySessionLogin) {
            return $legacySessionLogin;
        }

        $storedLogin = self::getStoredString(self::LOGIN);
        if ('' !== $storedLogin) {
            return $storedLogin;
        }

        $configurationLogin = trim((string)($configuration?->getBasisBankLogin() ?? ''));
        if ('' !== $configurationLogin) {
            return $configurationLogin;
        }

        $configLogin = (string)config('basisbank.login');
        if ('' !== $configLogin) {
            return $configLogin;
        }

        $legacyConfigLogin = (string)config('basisbank.api_token');
        if ('' !== $legacyConfigLogin) {
            return $legacyConfigLogin;
        }

        return (string)$configuration?->getBasisBankApiToken();
    }

    public static function getPassword(?Configuration $configuration = null): string
    {
        $sessionPassword = (string)session()->get(self::PASSWORD, '');
        if ('' !== $sessionPassword) {
            return $sessionPassword;
        }

        $legacySessionPassword = (string)session()->get(self::CONSENT_ID, '');
        if ('' !== $legacySessionPassword) {
            return $legacySessionPassword;
        }

        $storedPassword = self::getStoredString(self::PASSWORD);
        if ('' !== $storedPassword) {
            return $storedPassword;
        }

        $configurationPassword = trim((string)($configuration?->getBasisBankPassword() ?? ''));
        if ('' !== $configurationPassword) {
            return $configurationPassword;
        }

        $configPassword = (string)config('basisbank.password');
        if ('' !== $configPassword) {
            return $configPassword;
        }

        $legacyConfigPassword = (string)config('basisbank.consent_id');
        if ('' !== $legacyConfigPassword) {
            return $legacyConfigPassword;
        }

        return (string)$configuration?->getBasisBankConsentId();
    }

    public static function saveLogin(string $login): void
    {
        $value = trim($login);
        session()->put(self::LOGIN, $value);
        session()->put(self::API_TOKEN, $value);
        self::saveToStore(self::LOGIN, $value);
        self::saveToStore(self::API_TOKEN, $value);
    }

    public static function savePassword(string $password): void
    {
        $value = trim($password);
        session()->put(self::PASSWORD, $value);
        session()->put(self::CONSENT_ID, $value);
        self::saveToStore(self::PASSWORD, $value);
        self::saveToStore(self::CONSENT_ID, $value);
    }

    public static function getOtpCode(): string
    {
        $sessionOtp = (string)session()->get(self::OTP_CODE, '');
        if ('' !== $sessionOtp) {
            return $sessionOtp;
        }

        return (string)config('basisbank.otp_code');
    }

    public static function saveOtpCode(string $otpCode): void
    {
        session()->put(self::OTP_CODE, trim($otpCode));
    }

    public static function getRequestSmsCode(?Configuration $configuration = null): bool
    {
        if (session()->has(self::REQUEST_SMS_CODE)) {
            $sessionValue      = session()->get(self::REQUEST_SMS_CODE, false);
            $normalizedSession = self::boolValue($sessionValue);
            if (null !== $normalizedSession) {
                return $normalizedSession;
            }
        }

        $storedValue = self::getStoredBool(self::REQUEST_SMS_CODE);
        if (null !== $storedValue) {
            return $storedValue;
        }

        if (null !== $configuration && self::boolValue($configuration->getBasisBankRequestSmsCode()) !== null) {
            return (bool)$configuration->getBasisBankRequestSmsCode();
        }

        $configValue = (string)config('basisbank.request_sms_code');
        if ('' !== $configValue) {
            return self::asBool($configValue);
        }

        return true;
    }

    public static function saveRequestSmsCode(bool $requestSmsCode): void
    {
        session()->put(self::REQUEST_SMS_CODE, $requestSmsCode);
        self::saveToStore(self::REQUEST_SMS_CODE, $requestSmsCode);
    }

    public static function getTrustDevice(?Configuration $configuration = null): bool
    {
        if (session()->has(self::TRUST_DEVICE)) {
            $sessionValue      = session()->get(self::TRUST_DEVICE, false);
            $normalizedSession = self::boolValue($sessionValue);
            if (null !== $normalizedSession) {
                return $normalizedSession;
            }
        }

        $storedValue = self::getStoredBool(self::TRUST_DEVICE);
        if (null !== $storedValue) {
            return $storedValue;
        }

        if (null !== $configuration) {
            return (bool)$configuration->isBasisBankTrustDevice();
        }

        $configValue = (string)config('basisbank.trust_device');
        if ('' !== $configValue) {
            return self::asBool($configValue);
        }

        return false;
    }

    public static function saveTrustDevice(bool $trustDevice): void
    {
        session()->put(self::TRUST_DEVICE, $trustDevice);
        self::saveToStore(self::TRUST_DEVICE, $trustDevice);
    }

    public static function getAuthState(?Configuration $configuration = null): string
    {
        $sessionState = (string)session()->get(self::AUTH_STATE, '');
        if ('' !== $sessionState) {
            return $sessionState;
        }

        $storedState = self::getStoredString(self::AUTH_STATE);
        if ('' !== $storedState) {
            return $storedState;
        }

        $configurationState = trim((string)($configuration?->getBasisBankAuthState() ?? ''));
        if ('' !== $configurationState) {
            return $configurationState;
        }

        return (string)config('basisbank.auth_state');
    }

    public static function saveAuthState(string $authState): void
    {
        $value = trim($authState);
        session()->put(self::AUTH_STATE, $value);
        self::saveToStore(self::AUTH_STATE, $value);
    }

    public static function getSessionArtifact(?Configuration $configuration = null): string
    {
        $sessionArtifact = (string)session()->get(self::SESSION_ARTIFACT, '');
        if ('' !== $sessionArtifact) {
            return $sessionArtifact;
        }

        $storedArtifact = self::getStoredString(self::SESSION_ARTIFACT);
        if ('' !== $storedArtifact) {
            return $storedArtifact;
        }

        $configurationArtifact = trim((string)($configuration?->getBasisBankSessionArtifact() ?? ''));
        if ('' !== $configurationArtifact) {
            return $configurationArtifact;
        }

        return (string)config('basisbank.session_artifact');
    }

    public static function saveSessionArtifact(string $sessionArtifact): void
    {
        $value = trim($sessionArtifact);
        session()->put(self::SESSION_ARTIFACT, $value);
        self::saveToStore(self::SESSION_ARTIFACT, $value);
    }

    public static function clearSavedCredentials(): void
    {
        session()->forget(
            [
                self::API_TOKEN,
                self::CONSENT_ID,
                self::LOGIN,
                self::PASSWORD,
                self::OTP_CODE,
                self::AUTH_STATE,
                self::SESSION_ARTIFACT,
                self::REQUEST_SMS_CODE,
                self::TRUST_DEVICE,
            ]
        );
        try {
            self::store()->forgetProvider(self::PROVIDER);
        } catch (Throwable $e) {
            Log::warning(sprintf('Could not clear BasisBank persisted secrets: %s', $e->getMessage()));
        }
    }

    private static function boolValue(mixed $value): ?bool
    {
        if (true === $value || '1' === $value || 'true' === (string)$value || 'on' === (string)$value) {
            return true;
        }
        if (false === $value || '0' === $value || 'false' === (string)$value || '' === (string)$value || 'off' === (string)$value) {
            return false;
        }

        return null;
    }

    private static function asBool(mixed $value): bool
    {
        return self::boolValue($value) ?? (bool)$value;
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

    private static function getStoredBool(string $key): ?bool
    {
        $value = self::store()->get(self::PROVIDER, $key, null);

        return self::boolValue($value);
    }

    private static function saveToStore(string $key, mixed $value): void
    {
        try {
            self::store()->put(self::PROVIDER, $key, $value);
        } catch (Throwable $e) {
            Log::warning(sprintf('Could not persist BasisBank secret "%s": %s', $key, $e->getMessage()));
        }
    }
}
