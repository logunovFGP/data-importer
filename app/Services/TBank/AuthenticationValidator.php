<?php

declare(strict_types=1);

namespace App\Services\TBank;

use App\Exceptions\ImporterHttpException;
use App\Services\Enums\AuthenticationStatus;
use App\Services\Shared\Authentication\AuthenticationValidatorInterface;
use App\Services\TBank\Authentication\SecretManager;
use App\Services\TBank\OAuthClient;
use App\Services\TBank\Request\GetPingRequest;
use Illuminate\Support\Facades\Log;

class AuthenticationValidator implements AuthenticationValidatorInterface
{
    public function validate(): AuthenticationStatus
    {
        $sessionId = SecretManager::getSessionId();
        $cookieHeader = SecretManager::getCookieHeader();
        if ('' === $sessionId || '' === $cookieHeader) {
            return AuthenticationStatus::NODATA;
        }

        $request = new GetPingRequest($sessionId, $cookieHeader);
        $request->setTimeOut((float)config('importer.connection.timeout'));

        try {
            $request->get();
        } catch (ImporterHttpException $e) {
            Log::warning(sprintf('TBank auth validation failed: %s', $e->getMessage()));
            try {
                if (app(OAuthClient::class)->refreshAccessToken()) {
                    $sessionId = SecretManager::getSessionId();
                    $cookieHeader = SecretManager::getCookieHeader();
                    if ('' !== $sessionId && '' !== $cookieHeader) {
                        $request = new GetPingRequest($sessionId, $cookieHeader);
                        $request->setTimeOut((float)config('importer.connection.timeout'));
                        $request->get();

                        return AuthenticationStatus::AUTHENTICATED;
                    }
                }
            } catch (\Throwable $refreshException) {
                Log::warning(sprintf('TBank session refresh after failed validation failed: %s', $refreshException->getMessage()));
            }
            SecretManager::saveSessionId('');
            SecretManager::saveCookieHeader('');
            SecretManager::saveAuthState('UNKNOWN');

            return AuthenticationStatus::NODATA;
        }

        return AuthenticationStatus::AUTHENTICATED;
    }

    public function getData(): array
    {
        return [
            'device_pin' => SecretManager::getDevicePin(),
            'auth_state' => SecretManager::getAuthState(),
        ];
    }

    public function setData(array $data): void
    {
        $devicePin = trim((string)($data['device_pin'] ?? ''));
        if ('' !== $devicePin) {
            SecretManager::saveDevicePin($devicePin);
        }
        $sessionId = trim((string)($data['session_id'] ?? ''));
        if ('' !== $sessionId) {
            SecretManager::saveSessionId($sessionId);
        }
        $cookieHeader = trim((string)($data['cookie_header'] ?? ''));
        if ('' !== $cookieHeader) {
            SecretManager::saveCookieHeader($cookieHeader);
        }
        $token = trim((string)($data['api_token'] ?? ''));
        if ('' !== $token && '' === $sessionId) {
            SecretManager::saveSessionId($token);
        }
    }
}
