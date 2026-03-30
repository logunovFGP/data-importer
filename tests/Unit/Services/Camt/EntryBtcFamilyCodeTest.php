<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Camt;

use App\Services\Camt\TransactionCamt053;
use Genkgo\Camt\Camt053\DTO\Statement;
use Genkgo\Camt\DTO\BankTransactionCode;
use Genkgo\Camt\DTO\DomainBankTransactionCode;
use Genkgo\Camt\DTO\DomainFamilyBankTransactionCode;
use Genkgo\Camt\DTO\Entry;
use Genkgo\Camt\DTO\Message;
use Mockery;
use Tests\TestCase;

/**
 * Tests for Fix #8: Camt entryBtcFamilyCode returns empty string.
 *
 * The bug was that getFieldByIndex('entryBtcFamilyCode', ...) assigned the computed
 * value to $return but then executed `return ''` instead of `return $return`.
 *
 * @internal
 * @coversNothing
 */
final class EntryBtcFamilyCodeTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Build a TransactionCamt053 with mocked CAMT DTOs that return a known family code.
     */
    private function buildTransactionWithFamilyCode(string $familyCode): TransactionCamt053
    {
        // Use the real DomainFamilyBankTransactionCode — Mockery generic mock fails the return type check.
        $family = new DomainFamilyBankTransactionCode($familyCode, 'SUBFAM');

        $domainBtc = Mockery::mock(DomainBankTransactionCode::class);
        $domainBtc->shouldReceive('getCode')->andReturn('PMNT');
        $domainBtc->shouldReceive('getFamily')->andReturn($family);

        $btc = Mockery::mock(BankTransactionCode::class);
        $btc->shouldReceive('getDomain')->andReturn($domainBtc);

        $entry = Mockery::mock(Entry::class);
        $entry->shouldReceive('getBankTransactionCode')->andReturn($btc);

        $levelA = Mockery::mock(Message::class);
        $levelB = Mockery::mock(Statement::class);

        return new TransactionCamt053($levelA, $levelB, $entry, []);
    }

    public function testEntryBtcFamilyCodeReturnsComputedValue(): void
    {
        $transaction = $this->buildTransactionWithFamilyCode('RCDT');

        $result = $transaction->getFieldByIndex('entryBtcFamilyCode', 0);

        // Before the fix, this always returned ''. After the fix, it returns the actual family code.
        $this->assertSame('RCDT', $result);
    }

    public function testEntryBtcFamilyCodeReturnsEmptyWhenNoDomain(): void
    {
        // When the domain is not a DomainBankTransactionCode, result should be empty.
        $btc = Mockery::mock(BankTransactionCode::class);
        $btc->shouldReceive('getDomain')->andReturn(null);

        $entry = Mockery::mock(Entry::class);
        $entry->shouldReceive('getBankTransactionCode')->andReturn($btc);

        $levelA = Mockery::mock(Message::class);
        $levelB = Mockery::mock(Statement::class);

        $transaction = new TransactionCamt053($levelA, $levelB, $entry, []);
        $result = $transaction->getFieldByIndex('entryBtcFamilyCode', 0);

        $this->assertSame('', $result);
    }

    public function testEntryBtcDomainCodeStillWorks(): void
    {
        // Sanity check: the sibling field entryBtcDomainCode was already correct.
        $transaction = $this->buildTransactionWithFamilyCode('ICDT');

        $result = $transaction->getFieldByIndex('entryBtcDomainCode', 0);
        $this->assertSame('PMNT', $result);
    }
}
