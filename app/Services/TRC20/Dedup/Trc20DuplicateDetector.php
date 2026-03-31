<?php

/*
 * Trc20DuplicateDetector.php
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

namespace App\Services\TRC20\Dedup;

use App\Services\Shared\Dedup\DuplicateDetector;

/**
 * TRC20 dedup uses direction-qualified txHash: trc20|in|txHash or trc20|out|txHash.
 *
 * Legacy format was trc20|walletAddress|txHash. This detector searches both
 * formats during the migration period.
 *
 * Source name: "trc20"
 */
final class Trc20DuplicateDetector extends DuplicateDetector
{
    /** @var string[] Wallet addresses configured in this import */
    private array $wallets = [];

    public function sourceName(): string
    {
        return 'trc20';
    }

    public function setWallets(array $wallets): void
    {
        $this->wallets = $wallets;
    }

    public function extractKey(array $transaction): ?string
    {
        $externalId = trim((string) ($transaction['external_id'] ?? ''));
        if ('' === $externalId) {
            return null;
        }

        // If the key already uses the new format (trc20|in|... or trc20|out|...), use as-is
        if (str_starts_with($externalId, 'trc20|in|') || str_starts_with($externalId, 'trc20|out|')) {
            return $externalId;
        }

        // Otherwise it is the old format trc20|wallet|txHash -- pass through
        return $externalId;
    }

    /**
     * During migration, also search for old-format keys (trc20|walletAddress|txHash)
     * that may exist in Firefly III from previous imports.
     */
    public function extractLegacyKeys(array $transaction): array
    {
        $externalId = trim((string) ($transaction['external_id'] ?? ''));
        if ('' === $externalId) {
            return [];
        }

        $parts = explode('|', $externalId, 3);
        if (count($parts) < 3 || 'trc20' !== $parts[0]) {
            return [];
        }

        $direction = $parts[1]; // 'in' or 'out'
        $txHash    = $parts[2];

        // Only generate legacy keys for new-format IDs
        if ('in' !== $direction && 'out' !== $direction) {
            return [];
        }

        // Generate old-format keys for all configured wallets
        $legacyKeys = [];
        foreach ($this->wallets as $wallet) {
            $legacyKeys[] = sprintf('trc20|%s|%s', $wallet, $txHash);
        }

        return $legacyKeys;
    }
}
