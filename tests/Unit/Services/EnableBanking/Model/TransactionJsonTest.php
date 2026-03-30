<?php

declare(strict_types=1);

namespace Tests\Unit\Services\EnableBanking\Model;

use App\Services\EnableBanking\Model\Transaction;
use Tests\TestCase;

/**
 * Fix #51: json_encode() can return false, which was passed directly to
 * json_validate() without checking. After the fix, false is checked before
 * json_validate().
 *
 * Fix #59/#60: Transaction ID generation is now delegated to
 * TransactionIdGenerator::generateFallbackId().
 *
 * @internal
 *
 * @coversDefaultClass \App\Services\EnableBanking\Model\Transaction
 */
final class TransactionJsonTest extends TestCase
{
    /**
     * When transaction_id is provided, no fallback ID generation is needed.
     *
     * @covers ::fromArray
     */
    public function testTransactionIdUsedWhenPresent(): void
    {
        $array = $this->minimalArray(['transaction_id' => 'tx-001']);

        $transaction = Transaction::fromArray($array);

        $this->assertSame('tx-001', $transaction->transactionId);
    }

    /**
     * When transaction_id is missing, a fallback ID with 'eb-' prefix is generated.
     *
     * @covers ::fromArray
     */
    public function testFallbackIdGeneratedWhenTransactionIdMissing(): void
    {
        $array = $this->minimalArray();
        unset($array['transaction_id']);

        $transaction = Transaction::fromArray($array);

        $this->assertStringStartsWith('eb-', $transaction->transactionId);
        $this->assertNotEmpty($transaction->transactionId);
    }

    /**
     * When transaction_id is empty string, a fallback ID is generated.
     *
     * @covers ::fromArray
     */
    public function testFallbackIdGeneratedWhenTransactionIdEmpty(): void
    {
        $array = $this->minimalArray(['transaction_id' => '']);

        $transaction = Transaction::fromArray($array);

        $this->assertStringStartsWith('eb-', $transaction->transactionId);
    }

    /**
     * Deterministic fallback: same input array should produce the same ID.
     *
     * @covers ::fromArray
     */
    public function testSameArrayProducesSameFallbackId(): void
    {
        $array = $this->minimalArray();
        unset($array['transaction_id']);

        $tx1 = Transaction::fromArray($array);
        $tx2 = Transaction::fromArray($array);

        $this->assertSame($tx1->transactionId, $tx2->transactionId);
    }

    /**
     * getTransactionId() returns composite of accountUid and transactionId.
     *
     * @covers ::getTransactionId
     */
    public function testGetTransactionIdReturnsComposite(): void
    {
        $array = $this->minimalArray([
            'transaction_id' => 'my-tx-id',
            'account_uid'    => 'acct-42',
        ]);

        $transaction = Transaction::fromArray($array);

        $this->assertSame('acct-42-my-tx-id', $transaction->getTransactionId());
    }

    /**
     * getTransactionId() truncates long identifiers to 125 chars each.
     *
     * @covers ::getTransactionId
     */
    public function testGetTransactionIdTruncatesLongValues(): void
    {
        $longAccount = str_repeat('A', 200);
        $longTxId    = str_repeat('B', 200);

        $array = $this->minimalArray([
            'transaction_id' => $longTxId,
            'account_uid'    => $longAccount,
        ]);

        $transaction = Transaction::fromArray($array);
        $compositeId = $transaction->getTransactionId();

        // Each part is truncated to 125, plus the dash separator
        $this->assertLessThanOrEqual(251, strlen($compositeId));
    }

    /**
     * Build a minimal valid Enable Banking transaction array.
     */
    private function minimalArray(array $overrides = []): array
    {
        $defaults = [
            'transaction_id'     => '',
            'account_uid'        => 'test-account',
            'transaction_amount' => ['amount' => '10.00', 'currency' => 'EUR'],
            'booking_date'       => '2025-10-15',
            'status'             => 'booked',
        ];

        return array_merge($defaults, $overrides);
    }
}
