<?php

declare(strict_types=1);

namespace App\Services\Shared\Support;

use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

/**
 * Class TransactionIdGenerator
 *
 * Shared utility for generating fallback transaction IDs when the provider
 * does not supply one. Extracts common logic from Nordigen and EnableBanking
 * Transaction models.
 */
final class TransactionIdGenerator
{
    /**
     * Build a composite transaction ID from account identifier and raw transaction ID,
     * each truncated to 125 characters with whitespace collapsed.
     */
    public static function buildCompositeId(string $accountIdentifier, string $transactionId): string
    {
        $accountId     = substr(trim((string) preg_replace('/\s+/', ' ', $accountIdentifier)), 0, 125);
        $transactionId = substr(trim((string) preg_replace('/\s+/', ' ', $transactionId)), 0, 125);

        return trim(sprintf('%s-%s', $accountId, $transactionId));
    }

    /**
     * Generate a fallback transaction ID from an array of transaction data.
     * Uses a sha256 hash of the JSON-encoded array, falling back to serialize()
     * if JSON encoding fails. Both paths are deterministic — the same input
     * always produces the same ID.
     *
     * @param string $prefix  Provider-specific prefix (e.g., 'ff3', 'eb')
     * @param array  $array   The raw transaction data array
     *
     * @return string The generated transaction ID (e.g., "ff3-<uuid5>")
     */
    public static function generateFallbackId(string $prefix, array $array): string
    {
        try {
            $encoded = json_encode($array, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            ksort($array);
            $encoded = serialize($array);
            Log::warning(sprintf('generateFallbackId: json_encode failed, using serialize: %s', $e->getMessage()));
        }
        $hash = hash('sha256', $encoded);

        return sprintf('%s-%s', $prefix, Uuid::uuid5(config('importer.namespace'), $hash));
    }
}
