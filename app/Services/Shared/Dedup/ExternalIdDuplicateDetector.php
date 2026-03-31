<?php

/*
 * ExternalIdDuplicateDetector.php
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

/**
 * Generic duplicate detector using external_id as-is.
 * Suitable for any provider that sets a stable external_id.
 *
 * The source name is configurable -- set via DuplicateDetectorFactory
 * based on the flow name from config('importer.source_names').
 */
final class ExternalIdDuplicateDetector extends DuplicateDetector
{
    private string $source;

    public function __construct(
        ?BatchApiClient $batchClient,
        bool $batchAvailable,
        string $sourceName = 'unknown',
    ) {
        parent::__construct($batchClient, $batchAvailable);
        $this->source = $sourceName;
    }

    public function sourceName(): string
    {
        return $this->source;
    }

    public function extractKey(array $transaction): ?string
    {
        $externalId = trim((string) ($transaction['external_id'] ?? ''));

        return '' !== $externalId ? $externalId : null;
    }
}
