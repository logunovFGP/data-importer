<?php

declare(strict_types=1);

namespace App\Services\TBank\Request;

use App\Exceptions\ImporterHttpException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use JsonException;

abstract class SessionJsonRequest
{
    private float $timeOut = 3.14;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $sessionId,
        private readonly string $cookieHeader
    ) {}

    public function setTimeOut(float $timeOut): void
    {
        $this->timeOut = $timeOut;
    }

    protected function sessionId(): string
    {
        return $this->sessionId;
    }

    protected function getJson(string $path, array $query = [], array $headers = []): array
    {
        $client  = new Client(
            [
                'connect_timeout' => $this->timeOut,
                'timeout'         => $this->timeOut,
                'verify'          => config('importer.connection.verify'),
            ]
        );
        $url     = rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');
        $options = [
            'headers' => array_merge($this->defaultHeaders(), $headers),
            'query'   => $query,
        ];

        try {
            $response = $client->request('GET', $url, $options);
        } catch (TransferException $e) {
            $httpException             = new ImporterHttpException(sprintf('HTTP request failed for "%s": %s', $url, $e->getMessage()), 0, $e);
            $httpException->statusCode = method_exists($e, 'getResponse') && null !== $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;

            throw $httpException;
        }

        try {
            $decoded = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $httpException             = new ImporterHttpException(sprintf('Could not decode JSON from "%s": %s', $url, $e->getMessage()), 0, $e);
            $httpException->statusCode = $response->getStatusCode();

            throw $httpException;
        }
        if (!is_array($decoded)) {
            throw new ImporterHttpException(sprintf('Response from "%s" was not a JSON object or array.', $url));
        }
        if (isset($decoded['resultCode']) && !in_array(strtoupper((string)$decoded['resultCode']), ['OK', 'SUCCESS'], true)) {
            $message = trim((string)($decoded['plainMessage'] ?? $decoded['errorMessage'] ?? ''));
            if ('' === $message) {
                $message = sprintf('TBank API returned resultCode=%s.', (string)$decoded['resultCode']);
            }
            $httpException             = new ImporterHttpException($message);
            $httpException->statusCode = $response->getStatusCode();

            throw $httpException;
        }

        return $decoded;
    }

    protected function buildBaseQuery(array $overrides = []): array
    {
        $query = [
            'appName'    => (string)config('tbank.app_name'),
            'appVersion' => (string)config('tbank.app_version'),
            'platform'   => (string)config('tbank.platform'),
            'origin'     => (string)config('tbank.origin'),
            'sessionid'  => $this->sessionId,
        ];

        foreach ($overrides as $key => $value) {
            if (null === $value) {
                continue;
            }
            if (is_string($value) && '' === trim($value)) {
                continue;
            }
            $query[$key] = $value;
        }

        return $query;
    }

    /**
     * Return the first non-empty string value from a list of candidates.
     *
     * @param array  $values  Candidate values (may be null, empty, or non-string)
     * @param string $default Fallback if all candidates are empty
     */
    protected function firstNonEmpty(array $values, string $default = ''): string
    {
        foreach ($values as $value) {
            $stringValue = trim((string)$value);
            if ('' !== $stringValue) {
                return $stringValue;
            }
        }

        return $default;
    }

    private function defaultHeaders(): array
    {
        $headers = [
            'Accept'     => 'application/json',
            'User-Agent' => sprintf('FF3-data-importer/%s (%s)', config('importer.version'), config('importer.line_b')),
            'Origin'     => 'https://www.tbank.ru',
            'Referer'    => (string)config('tbank.referer', 'https://www.tbank.ru/mybank/'),
        ];
        if ('' !== trim($this->cookieHeader)) {
            $headers['Cookie'] = trim($this->cookieHeader);
        }

        return $headers;
    }
}
