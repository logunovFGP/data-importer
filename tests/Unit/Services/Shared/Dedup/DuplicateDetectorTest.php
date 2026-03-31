<?php

/*
 * DuplicateDetectorTest.php
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

namespace Tests\Unit\Services\Shared\Dedup;

use App\Services\Shared\Dedup\DuplicateCheckResult;
use App\Services\Shared\Dedup\DuplicateDetector;
use App\Services\Shared\Dedup\DuplicateKey;
use App\Services\Shared\Dedup\NullDuplicateDetector;
use Tests\TestCase;

/**
 * @internal
 *
 * @coversDefaultClass \App\Services\Shared\Dedup\DuplicateDetector
 */
final class DuplicateDetectorTest extends TestCase
{
    // ---------------------------------------------------------------
    // DuplicateKey
    // ---------------------------------------------------------------

    public function testDuplicateKeyCreationWithDefaults(): void
    {
        $key = new DuplicateKey('abc123', 'trc20');

        $this->assertSame('abc123', $key->value);
        $this->assertSame('trc20', $key->source);
        $this->assertSame('external_id', $key->field);
    }

    public function testDuplicateKeyCreationWithCustomField(): void
    {
        $key = new DuplicateKey('xyz', 'tbank', 'internal_reference');

        $this->assertSame('xyz', $key->value);
        $this->assertSame('tbank', $key->source);
        $this->assertSame('internal_reference', $key->field);
    }

    // ---------------------------------------------------------------
    // DuplicateCheckResult factory methods
    // ---------------------------------------------------------------

    public function testWithinBatchResult(): void
    {
        $key    = new DuplicateKey('dup1', 'trc20');
        $result = DuplicateCheckResult::withinBatch($key);

        $this->assertTrue($result->isDuplicate);
        $this->assertSame('within_batch', $result->source);
        $this->assertSame(0, $result->existingGroupId);
        $this->assertSame('', $result->description);
        $this->assertSame($key, $result->key);
    }

    public function testFromRemoteResult(): void
    {
        $key    = new DuplicateKey('remote1', 'nordigen');
        $match  = [
            'transaction_group_id' => 42,
            'description'          => 'Some existing transaction',
        ];
        $result = DuplicateCheckResult::fromRemote($key, $match);

        $this->assertTrue($result->isDuplicate);
        $this->assertSame('remote', $result->source);
        $this->assertSame(42, $result->existingGroupId);
        $this->assertSame('Some existing transaction', $result->description);
    }

    public function testFromRemoteResultWithMissingFields(): void
    {
        $key    = new DuplicateKey('remote2', 'tbank');
        $result = DuplicateCheckResult::fromRemote($key, []);

        $this->assertTrue($result->isDuplicate);
        $this->assertSame('remote', $result->source);
        $this->assertSame(0, $result->existingGroupId);
        $this->assertSame('', $result->description);
    }

    public function testUniqueResult(): void
    {
        $key    = new DuplicateKey('unique1', 'simplefin');
        $result = DuplicateCheckResult::unique($key);

        $this->assertFalse($result->isDuplicate);
        $this->assertSame('', $result->source);
        $this->assertSame(0, $result->existingGroupId);
        $this->assertSame('', $result->description);
    }

    // ---------------------------------------------------------------
    // NullDuplicateDetector
    // ---------------------------------------------------------------

    public function testNullDetectorReturnsNullForExtractKey(): void
    {
        $detector = new NullDuplicateDetector();

        $this->assertNull($detector->extractKey(['external_id' => 'abc']));
    }

    public function testNullDetectorSourceName(): void
    {
        $detector = new NullDuplicateDetector();

        $this->assertSame('', $detector->sourceName());
    }

    public function testNullDetectorIsDuplicateReturnsNull(): void
    {
        $detector = new NullDuplicateDetector();

        // extractKey returns null, so isDuplicate returns null (skip dedup)
        $this->assertNull($detector->isDuplicate(['external_id' => 'tx1', 'amount' => '10.00']));
    }

    // ---------------------------------------------------------------
    // DuplicateDetector — isDuplicate with index
    // ---------------------------------------------------------------

    public function testIsDuplicateReturnsUniqueWhenNotInIndex(): void
    {
        $detector = $this->createConcreteDetector();

        $result = $detector->isDuplicate(['external_id' => 'tx-001', 'amount' => '50.00']);

        $this->assertNotNull($result);
        $this->assertFalse($result->isDuplicate);
    }

    public function testIsDuplicateReturnsDuplicateWhenRemembered(): void
    {
        $detector = $this->createConcreteDetector();

        // Remember a key (simulates warm index or previous submission)
        $detector->remember('tx-001', ['transaction_group_id' => 42, 'description' => 'Existing']);

        $result = $detector->isDuplicate(['external_id' => 'tx-001', 'amount' => '50.00']);

        $this->assertNotNull($result);
        $this->assertTrue($result->isDuplicate);
        $this->assertSame('remote', $result->source);
        $this->assertSame(42, $result->existingGroupId);
    }

    public function testIsDuplicateReturnsNullWhenNoKeyExtractable(): void
    {
        $detector = $this->createConcreteDetector();

        // Transaction without external_id -- extractKey returns null
        $result = $detector->isDuplicate(['amount' => '25.00', 'description' => 'No ID here']);

        $this->assertNull($result);
    }

    // ---------------------------------------------------------------
    // DuplicateDetector — warmIndex / isWarmed
    // ---------------------------------------------------------------

    public function testIsWarmedInitiallyFalse(): void
    {
        $detector = $this->createConcreteDetector();

        $this->assertFalse($detector->isWarmed());
    }

    public function testWarmIndexSetsWarmedFlag(): void
    {
        $detector = $this->createConcreteDetector();

        $detector->warmIndex([]);

        $this->assertTrue($detector->isWarmed());
    }

    public function testWarmIndexWithTransactions(): void
    {
        $detector = $this->createConcreteDetector();

        $lines = [
            ['transactions' => [['external_id' => 'tx-warm-1']]],
            ['transactions' => [['external_id' => 'tx-warm-2']]],
        ];

        // Without batch client, warmIndex just sets the warmed flag
        $detector->warmIndex($lines);

        $this->assertTrue($detector->isWarmed());
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Creates a concrete DuplicateDetector subclass for testing.
     * Extracts keys from 'external_id' field when present.
     */
    private function createConcreteDetector(): DuplicateDetector
    {
        return new class (null, false) extends DuplicateDetector {
            public function sourceName(): string
            {
                return 'test_source';
            }

            public function extractKey(array $transaction): ?string
            {
                $extId = trim((string) ($transaction['external_id'] ?? ''));

                return '' !== $extId ? $extId : null;
            }
        };
    }
}
