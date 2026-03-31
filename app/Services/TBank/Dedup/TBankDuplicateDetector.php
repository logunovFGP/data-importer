<?php

/*
 * TBankDuplicateDetector.php
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

namespace App\Services\TBank\Dedup;

use App\Services\Shared\Dedup\DuplicateDetector;

/**
 * TBank uses operationId as external_id (stable, set by GetTransactionsRequest).
 * Falls back to md5 hash of [accountId, amount, currency, date, description, merchant].
 *
 * Source name: "tbank"
 */
final class TBankDuplicateDetector extends DuplicateDetector
{
    public function sourceName(): string
    {
        return 'tbank';
    }

    public function extractKey(array $transaction): ?string
    {
        $externalId = trim((string) ($transaction['external_id'] ?? ''));

        return '' !== $externalId ? $externalId : null;
    }
}
