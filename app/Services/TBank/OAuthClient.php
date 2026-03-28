<?php

declare(strict_types=1);

namespace App\Services\TBank;

use App\Exceptions\ImporterErrorException;
use App\Services\TBank\Authentication\SecretManager;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Support\Str;
use JsonException;

class OAuthClient
{
    /**
     * @throws ImporterErrorException
     */
    public function buildAuthorizationUrl(string $callbackUri = ''): string
    {
        $authorizeUrl = trim((string)config('tbank.session_authorize_url'));
        if ('' === $authorizeUrl) {
            throw new ImporterErrorException('TBank session authorize URL is not configured.');
        }

        $callbackUri = '' !== trim($callbackUri)
            ? trim($callbackUri)
            : trim((string)config('tbank.callback_uri'));
        if ('' === $callbackUri) {
            throw new ImporterErrorException('TBank callback URL is not configured.');
        }

        $clientState = Str::random(40);
        SecretManager::saveOAuthState($clientState);
        SecretManager::saveAuthState('PENDING');
        $completeUri = $this->appendQuery($callbackUri, ['ff_state' => $clientState]);

        $origin = trim((string)config('tbank.origin', 'web,ib5,platform'));
        try {
            $warmup = json_encode(['origin' => $origin], JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ImporterErrorException('Could not encode TBank warmup payload.', 0, $e);
        }

        $query = http_build_query(
            [
                'theme'        => 'default',
                'display'      => 'page',
                'origin'       => $origin,
                'complete_uri' => $completeUri,
                'warmup'       => $warmup,
            ],
            '',
            '&',
            PHP_QUERY_RFC3986
        );

        return sprintf('%s?%s', rtrim($authorizeUrl, '?'), $query);
    }

    /**
     * @throws ImporterErrorException
     */
    public function exchangeAuthorizationCode(string $code, string $state, string $sessionState, string $clientState = ''): void
    {
        $code         = trim($code);
        $state        = trim($state);
        $sessionState = trim($sessionState);
        if ('' === $code || '' === $state || '' === $sessionState) {
            throw new ImporterErrorException('TBank callback is missing code, state or session_state.');
        }

        $expectedClientState = SecretManager::getOAuthState();
        if ('' !== $expectedClientState) {
            if ('' !== trim($clientState) && !hash_equals($expectedClientState, trim($clientState))) {
                throw new ImporterErrorException('TBank callback state mismatch. Restart authentication.');
            }
        }

        [$decoded, $setCookieHeaders, $cookieHeaderFromJar] = $this->requestCheckAuth($code, $state, $sessionState);
        $messageCode = trim((string)($decoded['messageCode'] ?? ''));
        $sessionId   = $this->extractSessionId($decoded);
        $accessLevel = strtoupper(trim((string)($decoded['accessLevel'] ?? $decoded['payload']['accessLevel'] ?? '')));
        $cookieHeader = $this->mergeCookieHeaders(SecretManager::getCookieHeader(), $setCookieHeaders);
        $cookieHeader = $this->mergeCookieHeaderStrings($cookieHeader, $cookieHeaderFromJar);
        if ('' === $sessionId) {
            $sessionId = $this->extractSessionIdFromCookieHeader($cookieHeader);
        }

        if ('AUTHCOMPLETE' !== strtoupper($messageCode) || '' === $sessionId || in_array($accessLevel, ['', 'ANONYMOUS'], true)) {
            $errorMessage = trim((string)($decoded['plainMessage'] ?? $decoded['errorMessage'] ?? ''));
            if ('' === $errorMessage) {
                $errorMessage = sprintf(
                    'TBank authentication did not complete (messageCode=%s, accessLevel=%s).',
                    '' === $messageCode ? 'n/a' : $messageCode,
                    '' === $accessLevel ? 'n/a' : $accessLevel
                );
            }

            throw new ImporterErrorException($errorMessage);
        }
        if ('' === $cookieHeader) {
            throw new ImporterErrorException('TBank callback did not return reusable session cookies.');
        }

        SecretManager::saveSessionContext($sessionId, $cookieHeader, $accessLevel);
        SecretManager::clearOAuthState();
    }

    public function refreshAccessToken(): bool
    {
        $cookieHeader = SecretManager::getCookieHeader();
        if ('' === $cookieHeader) {
            return false;
        }

        $endpoint = trim((string)config('tbank.session_endpoint'));
        if ('' === $endpoint) {
            return false;
        }

        $client = $this->buildClient();
        $cookieJar = $this->createCookieJar($cookieHeader);
        try {
            $response = $client->request(
                'GET',
                $endpoint,
                [
                    'cookies' => $cookieJar,
                    'headers' => $this->defaultHeaders($cookieHeader, 'https://www.tbank.ru/'),
                    'query'   => [
                        'appName'    => (string)config('tbank.session_app_name'),
                        'appVersion' => (string)config('tbank.session_app_version'),
                        'origin'     => (string)config('tbank.origin'),
                    ],
                ]
            );
        } catch (TransferException) {
            return false;
        }

        $decoded = $this->decodeJson((string)$response->getBody());
        if (!is_array($decoded)) {
            return false;
        }
        if (isset($decoded['resultCode']) && 'OK' !== strtoupper((string)$decoded['resultCode'])) {
            return false;
        }

        $sessionId = trim((string)($decoded['payload'] ?? ''));
        $mergedCookieHeader = $this->mergeCookieHeaders($cookieHeader, $response->getHeader('Set-Cookie'));
        $mergedCookieHeader = $this->mergeCookieHeaderStrings($mergedCookieHeader, $this->cookieHeaderFromJar($cookieJar));
        if ('' !== $mergedCookieHeader) {
            SecretManager::saveCookieHeader($mergedCookieHeader);
        }
        if ('' === $sessionId) {
            $sessionId = $this->extractSessionIdFromCookieHeader($mergedCookieHeader);
            if ('' === $sessionId) {
                return false;
            }
        }

        SecretManager::saveSessionId($sessionId);
        SecretManager::saveAuthState('AUTHENTICATED');

        return true;
    }

    /**
     * @return array{0: array, 1: array, 2: string}
     * @throws ImporterErrorException
     */
    private function requestCheckAuth(string $code, string $state, string $sessionState): array
    {
        $url = trim((string)config('tbank.session_check_auth_url'));
        if ('' === $url) {
            throw new ImporterErrorException('TBank check_auth URL is not configured.');
        }

        $client  = $this->buildClient();
        $existingCookieHeader = SecretManager::getCookieHeader();
        $headers = $this->defaultHeaders($existingCookieHeader, 'https://www.tbank.ru/auth/');
        $cookieJar = $this->createCookieJar($existingCookieHeader);
        try {
            $response = $client->request(
                'GET',
                $url,
                [
                    'cookies' => $cookieJar,
                    'headers' => $headers,
                    'query'   => [
                        'code'          => $code,
                        'state'         => $state,
                        'session_state' => $sessionState,
                        'notInFrame'    => 'true',
                    ],
                ]
            );
        } catch (TransferException $e) {
            throw new ImporterErrorException(sprintf('TBank check_auth request failed: %s', $e->getMessage()), 0, $e);
        }

        $decoded = $this->decodeJson((string)$response->getBody());
        if (!is_array($decoded)) {
            throw new ImporterErrorException('TBank check_auth response is not valid JSON.');
        }

        return [$decoded, $response->getHeader('Set-Cookie'), $this->cookieHeaderFromJar($cookieJar)];
    }

    private function buildClient(): Client
    {
        return new Client(
            [
                'connect_timeout' => (float)config('importer.connection.timeout'),
                'timeout'         => (float)config('importer.connection.timeout'),
                'verify'          => config('importer.connection.verify'),
                'allow_redirects' => true,
            ]
        );
    }

    private function defaultHeaders(string $cookieHeader, string $referer): array
    {
        $headers = [
            'Accept'     => 'application/json',
            'User-Agent' => sprintf('FF3-data-importer/%s (%s)', config('importer.version'), config('importer.line_b')),
            'Origin'     => 'https://www.tbank.ru',
            'Referer'    => $referer,
        ];
        if ('' !== trim($cookieHeader)) {
            $headers['Cookie'] = trim($cookieHeader);
        }

        return $headers;
    }

    private function decodeJson(string $body): ?array
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    private function extractSessionId(array $decoded): string
    {
        $value = trim((string)($decoded['sessionId'] ?? $decoded['payload']['sessionId'] ?? ''));
        if ('' !== $value) {
            return $value;
        }

        return trim((string)($decoded['payload'] ?? ''));
    }

    private function mergeCookieHeaders(string $existingCookieHeader, array $setCookieHeaders): string
    {
        $cookies = [];
        foreach ($this->cookiePairsFromCookieHeader($existingCookieHeader) as $name => $value) {
            $cookies[$name] = $value;
        }
        foreach ($setCookieHeaders as $setCookieHeader) {
            $pair = trim((string)$setCookieHeader);
            if ('' === $pair) {
                continue;
            }
            [$firstPart] = explode(';', $pair, 2);
            if (!str_contains($firstPart, '=')) {
                continue;
            }
            [$name, $value] = explode('=', $firstPart, 2);
            $name = trim($name);
            $value = trim($value);
            if ('' === $name) {
                continue;
            }
            if ('' === $value) {
                unset($cookies[$name]);

                continue;
            }
            $cookies[$name] = $value;
        }

        return implode('; ', array_map(static fn (string $name, string $value): string => sprintf('%s=%s', $name, $value), array_keys($cookies), $cookies));
    }

    private function mergeCookieHeaderStrings(string $existingCookieHeader, string $incomingCookieHeader): string
    {
        $cookies = [];
        foreach ($this->cookiePairsFromCookieHeader($existingCookieHeader) as $name => $value) {
            $cookies[$name] = $value;
        }
        foreach ($this->cookiePairsFromCookieHeader($incomingCookieHeader) as $name => $value) {
            $cookies[$name] = $value;
        }

        return implode('; ', array_map(static fn (string $name, string $value): string => sprintf('%s=%s', $name, $value), array_keys($cookies), $cookies));
    }

    private function extractSessionIdFromCookieHeader(string $cookieHeader): string
    {
        $pairs = $this->cookiePairsFromCookieHeader($cookieHeader);
        foreach (['psid', 'old_session_id', 'sessionid'] as $key) {
            $value = trim((string)($pairs[$key] ?? ''));
            if ('' !== $value) {
                return $value;
            }
        }

        return '';
    }

    private function createCookieJar(string $cookieHeader): CookieJar
    {
        $jar = new CookieJar();
        foreach ($this->cookiePairsFromCookieHeader($cookieHeader) as $name => $value) {
            $jar->setCookie(new SetCookie(['Name' => $name, 'Value' => $value, 'Domain' => '.tbank.ru', 'Path' => '/']));
            $jar->setCookie(new SetCookie(['Name' => $name, 'Value' => $value, 'Domain' => 'www.tbank.ru', 'Path' => '/']));
        }

        return $jar;
    }

    private function cookieHeaderFromJar(CookieJar $cookieJar): string
    {
        $cookies = [];
        $now = time();
        foreach ($cookieJar->toArray() as $cookie) {
            $name = trim((string)($cookie['Name'] ?? ''));
            $value = trim((string)($cookie['Value'] ?? ''));
            if ('' === $name || '' === $value) {
                continue;
            }
            $expires = (int)($cookie['Expires'] ?? 0);
            if ($expires > 0 && $expires <= $now) {
                continue;
            }
            $cookies[$name] = $value;
        }

        return implode('; ', array_map(static fn (string $name, string $value): string => sprintf('%s=%s', $name, $value), array_keys($cookies), $cookies));
    }

    private function cookiePairsFromCookieHeader(string $cookieHeader): array
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

    private function appendQuery(string $url, array $query): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
