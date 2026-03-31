<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Nordigen;

use App\Services\Nordigen\Model\Transaction;
use Tests\TestCase;

/**
 * Fix HIGH-4: internalTransactionId must not silently wipe a valid transactionId.
 *
 * @internal
 *
 * @coversDefaultClass \App\Services\Nordigen\Model\Transaction
 */
final class TransactionInternalIdTest extends TestCase
{
    /**
     * When internalTransactionId is absent, the original transactionId must be preserved.
     *
     * @covers ::fromArray
     */
    public function testTransactionIdPreservedWhenInternalIdAbsent(): void
    {
        $array = [
            'key'                                  => 'booked',
            'transactionId'                        => 'ORIGINAL-TX-ID-123',
            'transactionAmount'                    => ['amount' => '-25.00', 'currency' => 'EUR'],
            'remittanceInformationUnstructured'     => 'Test payment',
            'account_id'                           => 'acct-456',
        ];
        // Notably: 'internalTransactionId' is NOT present in $array.

        $transaction = Transaction::fromArray($array);

        $this->assertSame('ORIGINAL-TX-ID-123', $transaction->transactionId);
    }

    /**
     * When internalTransactionId is present and non-empty, it should overwrite transactionId.
     *
     * @covers ::fromArray
     */
    public function testInternalIdOverwritesTransactionIdWhenPresent(): void
    {
        $array = [
            'key'                                  => 'booked',
            'transactionId'                        => 'ORIGINAL-TX-ID-123',
            'internalTransactionId'                => 'INTERNAL-ID-789',
            'transactionAmount'                    => ['amount' => '-25.00', 'currency' => 'EUR'],
            'remittanceInformationUnstructured'     => 'Test payment',
            'account_id'                           => 'acct-456',
        ];

        $transaction = Transaction::fromArray($array);

        $this->assertSame('INTERNAL-ID-789', $transaction->transactionId);
    }

    /**
     * When internalTransactionId is present but empty string, transactionId must not be wiped.
     *
     * @covers ::fromArray
     */
    public function testEmptyInternalIdDoesNotWipeTransactionId(): void
    {
        $array = [
            'key'                                  => 'booked',
            'transactionId'                        => 'ORIGINAL-TX-ID-123',
            'internalTransactionId'                => '',
            'transactionAmount'                    => ['amount' => '-25.00', 'currency' => 'EUR'],
            'remittanceInformationUnstructured'     => 'Test payment',
            'account_id'                           => 'acct-456',
        ];

        $transaction = Transaction::fromArray($array);

        $this->assertSame('ORIGINAL-TX-ID-123', $transaction->transactionId);
    }

    /**
     * When internalTransactionId is whitespace-only, transactionId must not be wiped.
     *
     * @covers ::fromArray
     */
    public function testWhitespaceOnlyInternalIdDoesNotWipeTransactionId(): void
    {
        $array = [
            'key'                                  => 'booked',
            'transactionId'                        => 'ORIGINAL-TX-ID-123',
            'internalTransactionId'                => '   ',
            'transactionAmount'                    => ['amount' => '-25.00', 'currency' => 'EUR'],
            'remittanceInformationUnstructured'     => 'Test payment',
            'account_id'                           => 'acct-456',
        ];

        $transaction = Transaction::fromArray($array);

        $this->assertSame('ORIGINAL-TX-ID-123', $transaction->transactionId);
    }
}
