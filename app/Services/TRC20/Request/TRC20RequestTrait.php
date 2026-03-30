<?php

declare(strict_types=1);

namespace App\Services\TRC20\Request;

/**
 * Shared TRC20/TronGrid request helpers used by GetTransactionsRequest,
 * GetTrxTransactionsRequest, and GetWalletRequest.
 *
 * Extracted to eliminate identical requestHeaders(), buildQuery(),
 * extractFingerprint(), and toMillisecondTimestamp() across multiple classes.
 *
 * Requires the using class to declare:
 *   - private readonly string $apiKey;
 *   - private readonly int $pageSize;  (only for buildQuery)
 */
trait TRC20RequestTrait
{
    /**
     * Build the TronGrid authentication headers.
     *
     * Returns an array with the TRON-PRO-API-KEY header when an API key is available.
     */
    private function requestHeaders(): array
    {
        $headers = [];
        if ('' !== trim($this->apiKey)) {
            $headers['TRON-PRO-API-KEY'] = $this->apiKey;
        }

        return $headers;
    }

    /**
     * Build the TronGrid query parameters for paginated, time-bounded requests.
     *
     * Appends end-of-day time (23:59:59) to dateTo so the entire last day is included.
     */
    private function buildQuery(?string $dateFrom, ?string $dateTo, ?string $fingerprint): array
    {
        $query = [
            'only_confirmed' => 'true',
            'limit'          => $this->pageSize,
            'order_by'       => 'block_timestamp,asc',
        ];

        $fromTimestamp = $this->toMillisecondTimestamp($dateFrom);
        if (null !== $fromTimestamp) {
            $query['min_timestamp'] = $fromTimestamp;
        }

        // Append end-of-day time so the entire last day is included.
        // Without this, '2025-10-16' resolves to midnight (start of day), dropping all transactions on that date.
        $dateToEndOfDay = (null !== $dateTo && '' !== trim($dateTo)) ? ($dateTo . ' 23:59:59') : $dateTo;
        $toTimestamp    = $this->toMillisecondTimestamp($dateToEndOfDay);
        if (null !== $toTimestamp) {
            $query['max_timestamp'] = $toTimestamp;
        }

        if (null !== $fingerprint && '' !== trim($fingerprint)) {
            $query['fingerprint'] = $fingerprint;
        }

        return $query;
    }

    /**
     * Extract the TronGrid pagination fingerprint from a response payload.
     */
    private function extractFingerprint(array $payload): ?string
    {
        $fingerprint = $payload['meta']['fingerprint'] ?? null;
        if (null !== $fingerprint && '' !== trim((string)$fingerprint)) {
            return (string)$fingerprint;
        }

        return null;
    }

    /**
     * Convert a date/datetime string to a millisecond Unix timestamp for TronGrid.
     */
    private function toMillisecondTimestamp(?string $value): ?int
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }

        $timestamp = strtotime($value);
        if (false === $timestamp) {
            return null;
        }

        return max(0, $timestamp * 1000);
    }
}
