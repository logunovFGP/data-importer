<?php

/*
 * BasisBankDuplicateDetector.php
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

namespace App\Services\BasisBank\Dedup;

use App\Services\Shared\Dedup\DuplicateDetector;

/**
 * BasisBank uses the external_id set by LunchFlow GenerateTransactions,
 * which comes from Transaction::getTransactionId() (the raw API transaction ID).
 *
 * Since BasisBank TransactionIDs are unstable (change across page loads),
 * the primary dedup key is the external_id as-is, but the within-batch
 * deduplication by description+date+amount in TransactionProcessor
 * remains essential and is NOT removed.
 *
 * Source name: "basisbank"
 */
final class BasisBankDuplicateDetector extends DuplicateDetector
{
    public function sourceName(): string
    {
        return 'basisbank';
    }

    public function extractKey(array $transaction): ?string
    {
        $externalId = trim((string) ($transaction['external_id'] ?? ''));

        return '' !== $externalId ? $externalId : null;
    }
}
