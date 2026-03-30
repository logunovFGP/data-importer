<?php

declare(strict_types=1);

namespace App\Services\Shared\Authentication;

use App\Services\BasisBank\Authentication\SecretManager as BasisBankSecretManager;
use App\Services\TBank\Authentication\SecretManager as TBankSecretManager;
use App\Services\TRC20\Authentication\SecretManager as TRC20SecretManager;
use Illuminate\Support\Facades\Crypt;

/**
 * Collects the current provider auth state from the session for persistence to the import job JSON.
 * Extracted from UploadController so any controller can re-snapshot auth at step transitions.
 *
 * Static credentials (passwords, PINs) are encrypted with the app key before storage.
 * Ephemeral session tokens are stored as-is (they rotate and expire naturally).
 */
class ProviderAuthCollector
{
    public static function collect(string $flow): array
    {
        return match ($flow) {
            'basisbank' => [
                'provider'         => 'basisbank',
                'session_artifact' => BasisBankSecretManager::getSessionArtifact(),
                'auth_state'       => BasisBankSecretManager::getAuthState(),
                'login'            => self::encrypt(BasisBankSecretManager::getLogin()),
                'password'         => self::encrypt(BasisBankSecretManager::getPassword()),
                'trust_device'     => BasisBankSecretManager::getTrustDevice(),
                '_encrypted'       => ['login', 'password'],
            ],
            'tbank' => [
                'provider'      => 'tbank',
                'session_id'    => TBankSecretManager::getSessionId(),
                'cookie_header' => TBankSecretManager::getCookieHeader(),
                'access_level'  => TBankSecretManager::getAccessLevel(),
                'auth_state'    => TBankSecretManager::getAuthState(),
                'device_pin'    => self::encrypt(TBankSecretManager::getDevicePin()),
                '_encrypted'    => ['device_pin'],
            ],
            'trc20' => [
                'provider' => 'trc20',
                'api_key'  => TRC20SecretManager::getApiKey(),
                'wallets'  => implode(',', TRC20SecretManager::getWallets()),
            ],
            default => [],
        };
    }

    /**
     * Decrypt a field value that was encrypted by collect().
     * Handles both encrypted and legacy plaintext values gracefully.
     */
    public static function decrypt(string $value): string
    {
        if ('' === $value) {
            return '';
        }
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            // Legacy plaintext value from before encryption was added
            return $value;
        }
    }

    private static function encrypt(string $value): string
    {
        if ('' === $value) {
            return '';
        }

        return Crypt::encryptString($value);
    }
}
