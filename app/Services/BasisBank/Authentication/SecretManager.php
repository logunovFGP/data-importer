<?php

declare(strict_types=1);

namespace App\Services\BasisBank\Authentication;

use App\Services\Shared\Configuration\Configuration;
use App\Services\Shared\Secrets\ProviderSecretStore;
use App\Services\Shared\Support\BooleanParser;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
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
        // Session values are encrypted at rest to protect credentials in file-based sessions.
        $sessionLogin = (string)session()->get(self::LOGIN, '');
        if ('' !== $sessionLogin) {
            return self::decryptSessionValue($sessionLogin);
        }

        $legacySessionLogin = (string)session()->get(self::API_TOKEN, '');
        if ('' !== $legacySessionLogin) {
            return self::decryptSessionValue($legacySessionLogin);
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
        // Session values are encrypted at rest to protect credentials in file-based sessions.
        $sessionPassword = (string)session()->get(self::PASSWORD, '');
        if ('' !== $sessionPassword) {
            return self::decryptSessionValue($sessionPassword);
        }

        $legacySessionPassword = (string)session()->get(self::CONSENT_ID, '');
        if ('' !== $legacySessionPassword) {
            return self::decryptSessionValue($legacySessionPassword);
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
        $value     = trim($login);
        // Encrypt credentials before storing in session to prevent plaintext exposure
        // in file-based session storage (default Laravel configuration).
        $encrypted = Crypt::encryptString($value);
        session()->put(self::LOGIN, $encrypted);
        session()->put(self::API_TOKEN, $encrypted);
        self::saveToStore(self::LOGIN, $value);
        self::saveToStore(self::API_TOKEN, $value);
    }

    public static function savePassword(string $password): void
    {
        $value     = trim($password);
        // Encrypt credentials before storing in session to prevent plaintext exposure
        // in file-based session storage (default Laravel configuration).
        $encrypted = Crypt::encryptString($value);
        session()->put(self::PASSWORD, $encrypted);
        session()->put(self::CONSENT_ID, $encrypted);
        self::saveToStore(self::PASSWORD, $value);
        self::saveToStore(self::CONSENT_ID, $value);
    }

    public static function getOtpCode(): string
    {
        // Session values are encrypted at rest to protect credentials in file-based sessions.
        $sessionOtp = (string)session()->get(self::OTP_CODE, '');
        if ('' !== $sessionOtp) {
            return self::decryptSessionValue($sessionOtp);
        }

        return (string)config('basisbank.otp_code');
    }

    public static function saveOtpCode(string $otpCode): void
    {
        // Encrypt OTP code before storing in session — sensitive credential.
        session()->put(self::OTP_CODE, Crypt::encryptString(trim($otpCode)));
    }

    public static function getRequestSmsCode(?Configuration $configuration = null): bool
    {
        if (session()->has(self::REQUEST_SMS_CODE)) {
            return BooleanParser::parse(session()->get(self::REQUEST_SMS_CODE, false));
        }

        $storedValue = self::getStoredBool(self::REQUEST_SMS_CODE);
        if (null !== $storedValue) {
            return $storedValue;
        }

        if (null !== $configuration) {
            return BooleanParser::parse($configuration->isBasisBankRequestSmsCode());
        }

        $configValue = (string)config('basisbank.request_sms_code');
        if ('' !== $configValue) {
            return BooleanParser::parse($configValue);
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
            return BooleanParser::parse(session()->get(self::TRUST_DEVICE, false));
        }

        $storedValue = self::getStoredBool(self::TRUST_DEVICE);
        if (null !== $storedValue) {
            return $storedValue;
        }

        if (null !== $configuration) {
            return BooleanParser::parse($configuration->isBasisBankTrustDevice());
        }

        $configValue = (string)config('basisbank.trust_device');
        if ('' !== $configValue) {
            return BooleanParser::parse($configValue);
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
        // Session values are encrypted at rest to protect auth state artifacts.
        $sessionState = (string)session()->get(self::AUTH_STATE, '');
        if ('' !== $sessionState) {
            return self::decryptSessionValue($sessionState);
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
        // Encrypt auth state before storing in session — may contain session tokens.
        session()->put(self::AUTH_STATE, Crypt::encryptString($value));
        self::saveToStore(self::AUTH_STATE, $value);
    }

    public static function getSessionArtifact(?Configuration $configuration = null): string
    {
        // Session values are encrypted at rest to protect session artifacts (cookies, tokens).
        $sessionArtifact = (string)session()->get(self::SESSION_ARTIFACT, '');
        if ('' !== $sessionArtifact) {
            return self::decryptSessionValue($sessionArtifact);
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
        // Encrypt session artifact before storing — may contain auth cookies/tokens.
        session()->put(self::SESSION_ARTIFACT, Crypt::encryptString($value));
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
        if (null === $value) {
            return null;
        }

        return BooleanParser::parse($value);
    }

    private static function saveToStore(string $key, mixed $value): void
    {
        try {
            self::store()->put(self::PROVIDER, $key, $value);
        } catch (Throwable $e) {
            Log::warning(sprintf('Could not persist BasisBank secret "%s": %s', $key, $e->getMessage()));
        }
    }

    /**
     * Decrypt a value retrieved from the session.
     *
     * Handles legacy plaintext values gracefully: if decryption fails (e.g. the value
     * was stored before encryption was introduced), the raw value is returned and a
     * deprecation warning is logged so operators can detect sessions that still contain
     * unencrypted credentials.
     */
    private static function decryptSessionValue(string $value): string
    {
        if ('' === $value) {
            return '';
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException $e) {
            Log::warning(sprintf(
                'BasisBank session value could not be decrypted (legacy plaintext fallback): %s',
                $e->getMessage()
            ));

            return $value;
        }
    }
}
