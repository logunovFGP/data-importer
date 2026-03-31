<?php

/*
 * DuplicateDetector.php
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

namespace App\Services\Shared\Dedup;

use App\Services\Shared\Import\Routine\BatchApiClient;
use Illuminate\Support\Facades\Log;

/**
 * Abstract base for provider-specific duplicate detection.
 *
 * Template method pattern:
 * - extractKey() is provider-specific (TRC20, BasisBank, etc.)
 * - sourceName() identifies the import provider (used as dedup namespace)
 * - isDuplicate() is shared (batch search against Firefly III)
 * - warmIndex() is shared (preload known external_ids, filtered by source)
 */
abstract class DuplicateDetector
{
    /** @var array<string, array|null> Maps external_id => existing transaction summary or null */
    protected array $index = [];
    protected bool $indexWarmed = false;

    public function __construct(
        protected readonly ?BatchApiClient $batchClient,
        protected readonly bool $batchAvailable,
    ) {}

    /**
     * Extract the dedup key from a transaction line.
     *
     * Returns null if the line has no usable key (skip dedup for this line).
     * The returned string is used as external_id in Firefly III.
     */
    abstract public function extractKey(array $transaction): ?string;

    /**
     * Return the source name for this detector.
     *
     * This string is sent to Firefly III as the `import_source` field and used
     * to namespace external_ids during dedup queries. Must match the value stored
     * in the `import_sources.name` column.
     *
     * Examples: "trc20", "tbank", "basisbank", "nordigen", "csv"
     */
    abstract public function sourceName(): string;

    /**
     * Extract legacy keys that should also be searched during migration periods.
     * Default: empty array. Override in providers that changed their key format.
     *
     * @return string[] Additional keys to check in the index
     */
    public function extractLegacyKeys(array $transaction): array
    {
        return [];
    }

    /**
     * Check if a transaction is a duplicate based on its external_id.
     */
    public function isDuplicate(array $transaction, array $expectedAccountIds = []): ?DuplicateCheckResult
    {
        $key = $this->extractKey($transaction);
        if (null === $key) {
            return null; // No key available, skip dedup
        }

        // Check primary key in index
        $match = $this->index[$key] ?? null;

        // Check legacy keys if primary not found
        if (null === $match) {
            foreach ($this->extractLegacyKeys($transaction) as $legacyKey) {
                $match = $this->index[$legacyKey] ?? null;
                if (null !== $match) {
                    Log::info(sprintf('Duplicate found via legacy key "%s" (primary: "%s", source: "%s").', $legacyKey, $key, $this->sourceName()));
                    break;
                }
            }
        }

        if (null === $match) {
            return DuplicateCheckResult::unique(new DuplicateKey($key, $this->sourceName()));
        }

        return DuplicateCheckResult::fromRemote(new DuplicateKey($key, $this->sourceName()), $match);
    }

    /**
     * Warm the index with known external_ids from Firefly III.
     *
     * When source-aware dedup is available (Firefly III advertises
     * capabilities.import_sources), the batch search is filtered by
     * this detector's sourceName() to prevent cross-provider collisions.
     *
     * @param array $lines All transaction lines to be imported
     */
    public function warmIndex(array $lines): void
    {
        $allKeys = [];
        foreach ($lines as $line) {
            foreach ($line['transactions'] ?? [] as $transaction) {
                $key = $this->extractKey($transaction);
                if (null !== $key) {
                    $allKeys[$key] = true;
                }
                foreach ($this->extractLegacyKeys($transaction) as $legacyKey) {
                    $allKeys[$legacyKey] = true;
                }
            }
        }

        if ([] === $allKeys) {
            $this->indexWarmed = true;
            return;
        }

        // Batch search when available
        if ($this->batchAvailable && null !== $this->batchClient) {
            $this->warmIndexViaBatch(array_keys($allKeys));
        }

        $this->indexWarmed = true;
    }

    /**
     * Remember a just-submitted external_id in the index.
     */
    public function remember(string $key, array $transactionSummary): void
    {
        $this->index[$key] = $transactionSummary;
    }

    public function isWarmed(): bool
    {
        return $this->indexWarmed;
    }

    private function warmIndexViaBatch(array $keys): void
    {
        try {
            $allKeys = array_map('strval', $keys);
            foreach (array_chunk($allKeys, 450) as $chunk) {
                // Pass source name to narrow the search to this provider's transactions.
                $result  = $this->batchClient->batchSearchExternalIds(
                    $chunk,
                    null,
                    null,
                    $this->sourceName(),
                );
                $matches = (array) ($result['results'] ?? []);
                foreach ($matches as $extId => $match) {
                    if (null !== $match && is_array($match)) {
                        $this->index[(string) $extId] = $match;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning(sprintf('DuplicateDetector[%s]: batch warmup failed: %s', $this->sourceName(), $e->getMessage()));
        }
    }
}
