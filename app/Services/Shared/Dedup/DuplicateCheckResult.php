<?php

/*
 * DuplicateCheckResult.php
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

final readonly class DuplicateCheckResult
{
    private function __construct(
        public bool   $isDuplicate,
        public DuplicateKey $key,
        public string $source,
        public int    $existingGroupId,
        public string $description,
    ) {}

    public static function withinBatch(DuplicateKey $key): self
    {
        return new self(true, $key, 'within_batch', 0, '');
    }

    public static function fromRemote(DuplicateKey $key, array $match): self
    {
        return new self(
            true,
            $key,
            'remote',
            (int) ($match['transaction_group_id'] ?? 0),
            (string) ($match['description'] ?? ''),
        );
    }

    public static function unique(DuplicateKey $key): self
    {
        return new self(false, $key, '', 0, '');
    }
}
