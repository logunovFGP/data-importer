<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Shared\Support;

use App\Services\Shared\Support\TransactionIdGenerator;
use Tests\TestCase;

/**
 * Fix #59/#60: Duplicated transaction ID generation logic in Nordigen and
 * EnableBanking Transaction models extracted to TransactionIdGenerator.
 *
 * @internal
 *
 * @coversDefaultClass \App\Services\Shared\Support\TransactionIdGenerator
 */
final class TransactionIdGeneratorTest extends TestCase
{
    /**
     * buildCompositeId() joins account and transaction ID with a dash.
     *
     * @covers ::buildCompositeId
     */
    public function testBuildCompositeIdJoinsParts(): void
    {
        $result = TransactionIdGenerator::buildCompositeId('acct-123', 'tx-456');

        $this->assertSame('acct-123-tx-456', $result);
    }

    /**
     * buildCompositeId() truncates each part to 125 characters.
     *
     * @covers ::buildCompositeId
     */
    public function testBuildCompositeIdTruncatesTo125Chars(): void
    {
        $longAccount = str_repeat('A', 200);
        $longTxId    = str_repeat('B', 200);

        $result = TransactionIdGenerator::buildCompositeId($longAccount, $longTxId);

        // Each part is 125 chars max, plus 1 dash = 251 max
        $this->assertLessThanOrEqual(251, strlen($result));
        $this->assertStringStartsWith(str_repeat('A', 125), $result);
        $this->assertStringEndsWith(str_repeat('B', 125), $result);
    }

    /**
     * buildCompositeId() collapses whitespace within each part.
     *
     * @covers ::buildCompositeId
     */
    public function testBuildCompositeIdCollapsesWhitespace(): void
    {
        $result = TransactionIdGenerator::buildCompositeId("acct  123\t456", "tx  789\n012");

        $this->assertSame('acct 123 456-tx 789 012', $result);
    }

    /**
     * buildCompositeId() trims leading/trailing whitespace.
     *
     * @covers ::buildCompositeId
     */
    public function testBuildCompositeIdTrimsWhitespace(): void
    {
        $result = TransactionIdGenerator::buildCompositeId('  acct  ', '  tx  ');

        $this->assertSame('acct-tx', $result);
    }

    /**
     * generateFallbackId() returns a prefixed UUID5 string.
     *
     * @covers ::generateFallbackId
     */
    public function testGenerateFallbackIdReturnsCorrectPrefix(): void
    {
        $result = TransactionIdGenerator::generateFallbackId('eb', ['key' => 'value']);

        $this->assertStringStartsWith('eb-', $result);
        // UUID5 format after prefix: 8-4-4-4-12 hex digits
        $this->assertMatchesRegularExpression('/^eb-[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $result);
    }

    /**
     * generateFallbackId() with ff3 prefix matches Nordigen format.
     *
     * @covers ::generateFallbackId
     */
    public function testGenerateFallbackIdWithFf3Prefix(): void
    {
        $result = TransactionIdGenerator::generateFallbackId('ff3', ['txId' => '123']);

        $this->assertStringStartsWith('ff3-', $result);
        $this->assertMatchesRegularExpression('/^ff3-[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $result);
    }

    /**
     * Same input array produces the same fallback ID (deterministic from JSON hash).
     *
     * @covers ::generateFallbackId
     */
    public function testDeterministicForSameInput(): void
    {
        $array = ['amount' => '10.00', 'currency' => 'EUR', 'date' => '2025-01-01'];

        $id1 = TransactionIdGenerator::generateFallbackId('eb', $array);
        $id2 = TransactionIdGenerator::generateFallbackId('eb', $array);

        $this->assertSame($id1, $id2);
    }

    /**
     * Different input arrays produce different fallback IDs.
     *
     * @covers ::generateFallbackId
     */
    public function testDifferentInputProducesDifferentId(): void
    {
        $id1 = TransactionIdGenerator::generateFallbackId('eb', ['amount' => '10.00']);
        $id2 = TransactionIdGenerator::generateFallbackId('eb', ['amount' => '20.00']);

        $this->assertNotSame($id1, $id2);
    }

    /**
     * Repeated calls with the same array always return the same ID,
     * even across separate invocations (no microtime / non-deterministic seed).
     *
     * @covers ::generateFallbackId
     */
    public function testGenerateFallbackIdIsDeterministicAcrossCalls(): void
    {
        $array = ['from' => 'TAddr1', 'to' => 'TAddr2', 'amount' => '100.5', 'ts' => '1700000000'];

        $results = [];
        for ($i = 0; $i < 5; ++$i) {
            $results[] = TransactionIdGenerator::generateFallbackId('ff3', $array);
        }

        // All five calls must return the identical ID.
        $this->assertCount(1, array_unique($results), 'generateFallbackId must be deterministic for the same input');
    }

    /**
     * Empty account identifier produces valid composite ID.
     *
     * @covers ::buildCompositeId
     */
    public function testBuildCompositeIdWithEmptyAccount(): void
    {
        $result = TransactionIdGenerator::buildCompositeId('', 'tx-123');

        $this->assertSame('-tx-123', $result);
    }

    /**
     * Empty transaction ID produces valid composite ID.
     *
     * @covers ::buildCompositeId
     */
    public function testBuildCompositeIdWithEmptyTransactionId(): void
    {
        $result = TransactionIdGenerator::buildCompositeId('acct-123', '');

        $this->assertSame('acct-123-', $result);
    }
}
