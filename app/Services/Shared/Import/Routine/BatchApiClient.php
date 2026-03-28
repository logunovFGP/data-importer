<?php

/*
 * BatchApiClient.php
 * Copyright (c) 2026 james@firefly-iii.org
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace App\Services\Shared\Import\Routine;

use App\Exceptions\ImporterErrorException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Class BatchApiClient
 *
 * HTTP client for Firefly III batch endpoints. Used by ApiSubmitter when the
 * connected Firefly III instance advertises batch capability via its /about
 * endpoint.
 */
class BatchApiClient
{
    private string       $baseUrl;
    private string       $token;
    private float        $timeout;
    private bool|string  $verify;

    public function __construct(string $baseUrl, string $token, float $timeout, bool|string $verify)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token   = $token;
        $this->timeout = $timeout;
        $this->verify  = $verify;
    }

    /**
     * Submit an array of transaction payloads as a single batch POST.
     *
     * @param  array $items  Array of transaction payloads (same shape as POST /v1/transactions body).
     *
     * @return array Decoded JSON response from Firefly III.
     *
     * @throws ImporterErrorException
     */
    public function batchStoreTransactions(array $items): array
    {
        $url      = sprintf('%s/api/v1/transactions/batch', $this->baseUrl);
        $response = $this->http()
            ->post($url, ['batch' => $items]);

        if (!$response->successful()) {
            throw new ImporterErrorException(
                sprintf('Batch store request failed: HTTP %d — %s', $response->status(), $response->body())
            );
        }

        $decoded = $response->json();
        if (!is_array($decoded)) {
            throw new ImporterErrorException('Batch store response is not valid JSON.');
        }

        return $decoded;
    }

    /**
     * Search for existing transactions by a list of external IDs in a single request.
     *
     * @param  array       $externalIds  List of external_id strings.
     * @param  string|null $startDate    Optional date filter (Y-m-d).
     * @param  string|null $endDate      Optional date filter (Y-m-d).
     *
     * @return array Decoded JSON response — keyed by external_id.
     *
     * @throws ImporterErrorException
     */
    public function batchSearchExternalIds(array $externalIds, ?string $startDate = null, ?string $endDate = null): array
    {
        $url  = sprintf('%s/api/v1/search/external-ids', $this->baseUrl);
        $body = ['external_ids' => array_values($externalIds)];

        if (null !== $startDate && '' !== $startDate) {
            $body['start_date'] = $startDate;
        }
        if (null !== $endDate && '' !== $endDate) {
            $body['end_date'] = $endDate;
        }

        $response = $this->http()
            ->post($url, $body);

        if (!$response->successful()) {
            throw new ImporterErrorException(
                sprintf('Batch external_id search failed: HTTP %d — %s', $response->status(), $response->body())
            );
        }

        $decoded = $response->json();
        if (!is_array($decoded)) {
            throw new ImporterErrorException('Batch external_id search response is not valid JSON.');
        }

        return $decoded;
    }

    /**
     * Probe the /about endpoint for batch capability flags.
     *
     * Returns true only when the Firefly III instance explicitly reports
     * `capabilities.batch_transactions === true`.
     */
    public function supportsBatchEndpoints(): bool
    {
        $url = sprintf('%s/api/v1/about', $this->baseUrl);

        try {
            $response = $this->http()->get($url);
        } catch (\Throwable $e) {
            Log::debug(sprintf('BatchApiClient: /about probe failed: %s', $e->getMessage()));

            return false;
        }

        if (!$response->successful()) {
            Log::debug(sprintf('BatchApiClient: /about returned HTTP %d', $response->status()));

            return false;
        }

        $json = $response->json();
        if (!is_array($json)) {
            return false;
        }

        $data         = $json['data'] ?? $json;
        $capabilities = $data['capabilities'] ?? [];

        return true === ($capabilities['batch_transactions'] ?? false);
    }

    /**
     * Build a pre-configured HTTP client matching the pattern used by
     * ApiSubmitter::fetchExistingExternalIds().
     */
    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withToken($this->token)
            ->acceptJson()
            ->timeout((int) ceil($this->timeout))
            ->withOptions(['verify' => $this->verify]);
    }
}
