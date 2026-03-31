<?php

/*
 * DuplicateDetectorFactory.php
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

use App\Services\BasisBank\Dedup\BasisBankDuplicateDetector;
use App\Services\Nordigen\Dedup\NordigenDuplicateDetector;
use App\Services\Shared\Import\Routine\BatchApiClient;
use App\Services\TBank\Dedup\TBankDuplicateDetector;
use App\Services\TRC20\Dedup\Trc20DuplicateDetector;

final class DuplicateDetectorFactory
{
    /**
     * Create the appropriate detector for the given flow.
     *
     * The detector's sourceName() is used for:
     * 1. Filtering batch external_id searches by source in Firefly III
     * 2. Setting import_source on newly created transactions
     * 3. Logging and diagnostics
     */
    public static function create(
        string $flow,
        ?BatchApiClient $batchClient,
        bool $batchAvailable,
    ): DuplicateDetector {
        return match ($flow) {
            'trc20'     => new Trc20DuplicateDetector($batchClient, $batchAvailable),
            'basisbank' => new BasisBankDuplicateDetector($batchClient, $batchAvailable),
            'tbank'     => new TBankDuplicateDetector($batchClient, $batchAvailable),
            'nordigen'  => new NordigenDuplicateDetector($batchClient, $batchAvailable),
            default     => new ExternalIdDuplicateDetector(
                $batchClient,
                $batchAvailable,
                self::resolveSourceName($flow),
            ),
        };
    }

    /**
     * Resolve the source name for generic flows.
     *
     * Reads from config('importer.dedup.source_names') map. Falls back to
     * the flow name itself if no mapping is defined.
     */
    private static function resolveSourceName(string $flow): string
    {
        $map = config('importer.dedup.source_names', []);

        return (string) ($map[$flow] ?? $flow);
    }
}
