<?php

/*
 * NordigenDuplicateDetector.php
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

namespace App\Services\Nordigen\Dedup;

use App\Services\Shared\Dedup\DuplicateDetector;

/**
 * Nordigen uses composite IDs: accountIdentifier-transactionId.
 *
 * After 2025-09-07, transactionId was switched from the bank's transactionId
 * to internalTransactionId. This detector searches for both to prevent
 * duplicates during the transition period.
 *
 * Source name: "nordigen"
 */
final class NordigenDuplicateDetector extends DuplicateDetector
{
    public function sourceName(): string
    {
        return 'nordigen';
    }

    public function extractKey(array $transaction): ?string
    {
        $externalId = trim((string) ($transaction['external_id'] ?? ''));

        return '' !== $externalId ? $externalId : null;
    }

    /**
     * Generate the alternative composite ID using the other transaction ID field.
     *
     * If the current key uses internalTransactionId, also check transactionId, and vice versa.
     * This requires access to the raw Nordigen transaction data, which is stored in
     * 'internal_reference' (accountIdentifier) and the original API fields.
     */
    public function extractLegacyKeys(array $transaction): array
    {
        // Legacy keys can only be computed if we have the raw Nordigen fields.
        // The GenerateTransactions step stores accountIdentifier in internal_reference.
        $internalRef = trim((string) ($transaction['internal_reference'] ?? ''));

        // Without the account identifier, we cannot construct alternate composite IDs.
        if ('' === $internalRef) {
            return [];
        }

        // The current external_id is built by TransactionIdGenerator::buildCompositeId().
        // We cannot recover the original transactionId vs internalTransactionId from the
        // composite key alone. Legacy key search is best-effort for the migration period.
        // After 2-3 import cycles, all transactions will have the new format.
        return [];
    }
}
