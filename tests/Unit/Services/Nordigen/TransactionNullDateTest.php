<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Nordigen;

use App\Services\Nordigen\Model\Transaction;
use Tests\TestCase;

/**
 * @internal
 *
 * @coversDefaultClass \App\Services\Nordigen\Model\Transaction
 */
final class TransactionNullDateTest extends TestCase
{
    /**
     * Fix #9: toLocalArray() must not crash when bookingDate and valueDate are null.
     * Previously, calling ->toW3cString() on null Carbon fields caused a fatal error.
     *
     * @covers ::toLocalArray
     */
    public function testToLocalArrayWithNullDatesReturnsEmptyStrings(): void
    {
        // Minimal array that does NOT include bookingDate or valueDate keys,
        // so both fields remain null on the Transaction object.
        $array       = [
            'key'                         => 'pending',
            'transactionAmount'           => ['amount' => '-10.00', 'currency' => 'EUR'],
            'internalTransactionId'       => 'test-txn-001',
            'remittanceInformationUnstructured' => 'Test transaction',
            'account_id'                  => 'acct-123',
        ];

        $transaction = Transaction::fromArray($array);

        // Confirm the dates are actually null before testing toLocalArray.
        $this->assertNull($transaction->bookingDate);
        $this->assertNull($transaction->valueDate);

        // This must not throw — previously it crashed with "Call to a member function toW3cString() on null".
        $local       = $transaction->toLocalArray();

        $this->assertIsArray($local);
        $this->assertArrayHasKey('booking_date', $local);
        $this->assertArrayHasKey('value_date', $local);
        $this->assertSame('', $local['booking_date'], 'Null bookingDate must produce empty string');
        $this->assertSame('', $local['value_date'], 'Null valueDate must produce empty string');
    }
}
