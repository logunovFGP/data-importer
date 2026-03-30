<?php

declare(strict_types=1);

namespace App\Services\BasisBank;

use App\Exceptions\ImporterHttpException;
use App\Services\BasisBank\Authentication\SecretManager;
use App\Services\BasisBank\Auth\BasisBankSessionState;
use App\Services\BasisBank\Request\GetPingRequest;
use App\Services\Enums\AuthenticationStatus;
use App\Services\Shared\Authentication\AuthenticationValidatorInterface;
use App\Services\Shared\Support\BooleanParser;
use Illuminate\Support\Facades\Log;

class AuthenticationValidator implements AuthenticationValidatorInterface
{
    public function validate(): AuthenticationStatus
    {
        $state           = SecretManager::getAuthState();
        $sessionArtifact = SecretManager::getSessionArtifact();
        if ('' === $state) {
            $state = BasisBankSessionState::UNKNOWN;
        }

        if (BasisBankSessionState::AUTH_FAILED === $state) {
            Log::warning(sprintf('[BasisBank] Validation state AUTH_FAILED. Falling back to re-authentication.'));
            SecretManager::saveAuthState(BasisBankSessionState::UNKNOWN);
            SecretManager::saveSessionArtifact('');

            return AuthenticationStatus::NODATA;
        }

        if (in_array($state, [BasisBankSessionState::OTP_REQUIRED, BasisBankSessionState::TRUST_DEVICE_REQUIRED], true)) {
            return AuthenticationStatus::NODATA;
        }

        if ('' === trim($sessionArtifact)) {
            Log::info(sprintf('[BasisBank] Validation missing session artifact for state "%s".', $state));

            return AuthenticationStatus::NODATA;
        }

        $request = new GetPingRequest('', '', $sessionArtifact);
        $request->setTimeOut((float)config('importer.connection.timeout'));
        try {
            $request->get();
        } catch (ImporterHttpException $e) {
            Log::warning(sprintf('BasisBank web-session validation failed, require re-authentication: %s', $e->getMessage()));
            SecretManager::saveAuthState(BasisBankSessionState::UNKNOWN);
            SecretManager::saveSessionArtifact('');

            return AuthenticationStatus::NODATA;
        }

        return AuthenticationStatus::AUTHENTICATED;
    }

    public function getData(): array
    {
        return [
            'api_token'        => SecretManager::getLogin(),
            'consent_id'       => SecretManager::getPassword(),
            'otp_code'         => SecretManager::getOtpCode(),
            'request_sms_code' => SecretManager::getRequestSmsCode() ? '1' : '0',
            'trust_device'     => SecretManager::getTrustDevice() ? '1' : '0',
            'auth_state'       => SecretManager::getAuthState(),
            'session_artifact' => SecretManager::getSessionArtifact(),
        ];
    }

    public function setData(array $data): void
    {
        $login           = trim((string)($data['api_token'] ?? $data['login'] ?? ''));
        $password        = trim((string)($data['consent_id'] ?? $data['password'] ?? ''));
        $otpCode         = trim((string)($data['otp_code'] ?? ''));
        $requestSmsCode  = BooleanParser::parse($data['request_sms_code'] ?? null);
        $trustDevice     = BooleanParser::parse($data['trust_device'] ?? null);
        $authState       = trim((string)($data['auth_state'] ?? ''));
        $sessionArtifact = trim((string)($data['session_artifact'] ?? ''));

        SecretManager::saveApiToken($login);
        SecretManager::saveConsentId($password);
        SecretManager::saveOtpCode($otpCode);
        SecretManager::saveRequestSmsCode($requestSmsCode);
        SecretManager::saveTrustDevice($trustDevice);
        SecretManager::saveAuthState($authState);
        SecretManager::saveSessionArtifact($sessionArtifact);
    }

}
