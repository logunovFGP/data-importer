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

        $options = [
            'headers' => array_merge(
                [
                    'Accept'        => 'application/json',
                    'Authorization' => sprintf('Bearer %s', $this->apiToken),
                    'User-Agent'    => sprintf('FF3-data-importer/%s (%s)', config('importer.version'), config('importer.line_b')),
                ],
                $headers
            ),
            'query'   => $query,
        ];

        try {
            $response = $client->request('GET', $url, $options);
        } catch (TransferException $e) {
            $httpException             = new ImporterHttpException(sprintf('HTTP request failed for "%s": %s', $url, $e->getMessage()), 0, $e);
            $httpException->statusCode = method_exists($e, 'getResponse') && null !== $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;

            throw $httpException;
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
