<?php

declare(strict_types=1);

namespace App\Services\Shared\Request;

use App\Exceptions\ImporterHttpException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use JsonException;

abstract class BearerJsonRequest
{
    private float $timeOut = 3.14;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiToken
    ) {}

    public function setTimeOut(float $timeOut): void
    {
        $this->timeOut = $timeOut;
    }

    /**
     * @throws ImporterHttpException
     */
    protected function getJson(string $path, array $headers = [], array $query = []): array
    {
        $client  = new Client(
            [
                'connect_timeout' => $this->timeOut,
                'timeout'         => $this->timeOut,
                'verify'          => config('importer.connection.verify'),
            ]
        );
        $url     = rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');

        $defaultHeaders = [
            'Accept'    => 'application/json',
            'User-Agent' => sprintf('FF3-data-importer/%s (%s)', config('importer.version'), config('importer.line_b')),
        ];

        // Only add Bearer auth when the caller does not supply its own auth header
        // (e.g., TronGrid uses TRON-PRO-API-KEY instead of Bearer)
        if (!array_key_exists('TRON-PRO-API-KEY', $headers) && '' !== $this->apiToken) {
            $defaultHeaders['Authorization'] = sprintf('Bearer %s', $this->apiToken);
        }

        $options = [
            'headers' => array_merge($defaultHeaders, $headers),
            'query'   => $query,
        ];

        try {
            $response = $client->request('GET', $url, $options);
        } catch (TransferException $e) {
            $statusCode = method_exists($e, 'getResponse') && null !== $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;

            // If a provider API key caused 401, retry without it (e.g., TronGrid works without key)
            if (401 === $statusCode && array_key_exists('TRON-PRO-API-KEY', $options['headers'])) {
                \Illuminate\Support\Facades\Log::warning('TronGrid API key rejected (401). Retrying without API key.');
                unset($options['headers']['TRON-PRO-API-KEY']);
                try {
                    $response = $client->request('GET', $url, $options);
                } catch (TransferException $retryException) {
                    $httpException             = new ImporterHttpException(sprintf('HTTP request failed for "%s": %s', $url, $retryException->getMessage()), 0, $retryException);
                    $httpException->statusCode = method_exists($retryException, 'getResponse') && null !== $retryException->getResponse() ? $retryException->getResponse()->getStatusCode() : 0;

                    throw $httpException;
                }
            } else {
                $httpException             = new ImporterHttpException(sprintf('HTTP request failed for "%s": %s', $url, $e->getMessage()), 0, $e);
                $httpException->statusCode = $statusCode;

                throw $httpException;
            }
        }

        $body = (string)$response->getBody();
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $httpException             = new ImporterHttpException(sprintf('Could not decode JSON from "%s": %s', $url, $e->getMessage()), 0, $e);
            $httpException->statusCode = $response->getStatusCode();

            throw $httpException;
        }

        if (!is_array($decoded)) {
            throw new ImporterHttpException(sprintf('Response from "%s" was not a JSON object or array.', $url));
        }

        return $decoded;
    }
}
