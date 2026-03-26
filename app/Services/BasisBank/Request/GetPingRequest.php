<?php

/*
 * GetPingRequest.php
 */

declare(strict_types=1);

namespace App\Services\BasisBank\Request;

use App\Exceptions\ImporterHttpException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;

class GetPingRequest
{
    private const string BASE_API_URL = 'https://api.basisbank.com/psd2/v1/';
    private const string BASE_WEB_URL = 'https://www.bankonline.ge';
    private const string WEB_PING_PATH = '/Balance.aspx';
    private const string API_ACCOUNT_PATH = 'accounts';
    private const float  TIMEOUT_SECONDS = 3.14;

    private float $timeOut = self::TIMEOUT_SECONDS;

    public function __construct(
        private readonly string $apiToken = '',
        private readonly string $consentId = '',
        private readonly string $sessionArtifact = ''
    ) {
    }

    public function setTimeOut(float $timeOut): void
    {
        $this->timeOut = $timeOut;
    }

    /**
     * @throws ImporterHttpException
     */
    public function get(): bool
    {
        if (true === config('importer.fake_data')) {
            return true;
        }

        if ('' !== trim($this->sessionArtifact)) {
            return $this->webSessionPing();
        }

        if ('' === trim($this->apiToken) || '' === trim($this->consentId)) {
            throw new ImporterHttpException('BasisBank ping request requires web session artifact or API token/consent credentials.');
        }

        return $this->apiTokenPing();
    }

    private function apiTokenPing(): bool
    {
        $path = trim((string)config('basisbank.accounts_endpoint', self::API_ACCOUNT_PATH));
        if ('' === $path) {
            $path = self::API_ACCOUNT_PATH;
        }

        $client = new Client(
            [
                'connect_timeout' => $this->timeOut,
                'timeout'         => $this->timeOut,
                'verify'          => config('importer.connection.verify'),
            ]
        );
        $url = rtrim((string)config('basisbank.api_url', self::BASE_API_URL), '/') . '/' . ltrim($path, '/');

        try {
            $response = $client->request(
                'GET',
                $url,
                [
                    'headers' => array_merge(
                        [
                            'Accept'        => 'application/json',
                            'Authorization' => sprintf('Bearer %s', $this->apiToken),
                            'User-Agent'    => sprintf('FF3-data-importer/%s (%s)', config('importer.version'), config('importer.line_b')),
                        ],
                        $this->apiHeaders()
                    ),
                    'query' => [],
                ]
            );
        } catch (TransferException $e) {
            $httpException             = new ImporterHttpException(sprintf('BasisBank API ping request failed for "accounts": %s', $e->getMessage()), 0, $e);
            $httpException->statusCode = method_exists($e, 'getResponse') && null !== $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;

            throw $httpException;
        }

        $body = (string)$response->getBody();
        $decoded = null;
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $httpException             = new ImporterHttpException(sprintf('BasisBank API ping returned non-JSON response from "%s": %s', $url, $e->getMessage()), 0, $e);
            $httpException->statusCode = $response->getStatusCode();
            throw $httpException;
        }

        if (!is_array($decoded)) {
            $httpException             = new ImporterHttpException(sprintf('BasisBank API ping returned invalid response from "%s".', $url));
            $httpException->statusCode = $response->getStatusCode();
            throw $httpException;
        }

        return true;
    }

    private function webSessionPing(): bool
    {
        $cookies = $this->decodeArtifact($this->sessionArtifact);
        if ([] === $cookies) {
            throw new ImporterHttpException('BasisBank web-session ping failed: missing/invalid session artifact.');
        }

        $cookieHeader = $this->buildCookieHeader($cookies);
        $client       = new Client(
            [
                'base_uri'        => self::BASE_WEB_URL,
                'connect_timeout' => $this->timeOut,
                'timeout'         => $this->timeOut,
                'verify'          => config('importer.connection.verify'),
            ]
        );

        try {
            $response = $client->request(
                'GET',
                self::WEB_PING_PATH,
                [
                    'headers'         => [
                        'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                        'Cookie'     => $cookieHeader,
                        'User-Agent' => sprintf('FF3-data-importer/%s (%s)', config('importer.version'), config('importer.line_b')),
                    ],
                    'allow_redirects' => false,
                ]
            );
        } catch (TransferException $e) {
            $httpException             = new ImporterHttpException(sprintf('BasisBank web-session ping request failed for "%s": %s', self::WEB_PING_PATH, $e->getMessage()), 0, $e);
            $httpException->statusCode = method_exists($e, 'getResponse') && null !== $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
            throw $httpException;
        }

        $status = (int)$response->getStatusCode();
        $location = trim((string)$response->getHeaderLine('Location'));
        if (302 === $status) {
            $locationInfo = '' === $location ? '[empty Location header]' : $location;
            logger()->warning(sprintf('BasisBank web-session ping redirect detected: HTTP 302 Location="%s".', $locationInfo));
            $message = sprintf('BasisBank web-session ping returned HTTP 302. Redirect Location: %s', $locationInfo);
            if (str_contains(strtolower($location), 'login.aspx')) {
                $message = sprintf('BasisBank web-session ping received login redirect, session is not valid. Redirect Location: %s', $locationInfo);
            }
            $httpException             = new ImporterHttpException($message);
            $httpException->statusCode = $status;
            throw $httpException;
        }

        if (401 === $status || 403 === $status || 440 === $status) {
            $httpException             = new ImporterHttpException(sprintf('BasisBank web-session ping unauthorized (%d).', $status));
            $httpException->statusCode = $status;
            throw $httpException;
        }

        if ($status >= 300) {
            $httpException             = new ImporterHttpException(sprintf('BasisBank web-session ping returned HTTP %d.', $status));
            $httpException->statusCode = $status;
            throw $httpException;
        }

        $body = (string)$response->getBody();
        if ($this->containsLoginForm($body)) {
            throw new ImporterHttpException('BasisBank web-session ping returned a login form, session is not valid.');
        }

        return true;
    }

    private function apiHeaders(): array
    {
        $headers = [];

        if ('' !== $this->consentId) {
            $headers['Consent-ID'] = $this->consentId;
        }

        $psuIp = trim((string)config('basisbank.psu_ip_address', ''));
        if ('' !== $psuIp) {
            $headers['PSU-IP-Address'] = $psuIp;
        }

        $psuId = trim((string)config('basisbank.psu_id', ''));
        if ('' !== $psuId) {
            $headers['PSU-ID'] = $psuId;
        }

        return $headers;
    }

    private function decodeArtifact(string $artifact): array
    {
        if ('' === trim($artifact)) {
            return [];
        }

        $decoded = base64_decode($artifact, true);
        if (false === $decoded) {
            $decoded = $artifact;
        }

        $data = json_decode((string)$decoded, true);
        if (!is_array($data)) {
            return [];
        }

        $cookies = [];
        foreach ($data as $name => $value) {
            if ('' === trim((string)$name) || !is_string($value)) {
                continue;
            }
            $cookies[(string)$name] = (string)$value;
        }

        return $cookies;
    }

    private function buildCookieHeader(array $cookies): string
    {
        $parts = [];
        foreach ($cookies as $name => $value) {
            if ('' === trim((string)$name)) {
                continue;
            }
            $parts[] = sprintf('%s=%s', (string)$name, (string)$value);
        }

        return implode('; ', $parts);
    }

    private function containsLoginForm(string $html): bool
    {
        return str_contains($html, 'id="UTXT"') && str_contains($html, 'id="PTXT"');
    }
}
