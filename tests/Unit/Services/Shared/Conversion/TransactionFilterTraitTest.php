<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Shared\Conversion;

use App\Services\Shared\Conversion\TransactionFilterTrait;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Fix #53: Duplicated filterTransactions() in EnableBanking and LunchFlow
 * TransactionProcessors extracted to TransactionFilterTrait.
 *
 * Fix #54: LunchFlow filterTransactions() never filtered pending transactions.
 * After extraction, isTransactionPending() is provider-specific and must be tested.
 *
 * @internal
 *
 * @coversNothing
 */
final class TransactionFilterTraitTest extends TestCase
{
    /**
     * Pending transactions should be excluded when $getPending is false.
     */
    public function testPendingTransactionsExcludedWhenNotGetPending(): void
    {
        $processor = $this->createProcessor(pendingCheck: fn ($tx) => $tx->pending);

        $transactions = $this->makeCountableIterator([
            $this->makeTx('10.00', '2025-06-15', false),
            $this->makeTx('20.00', '2025-06-15', true),  // pending
            $this->makeTx('30.00', '2025-06-15', false),
        ]);

        $result = $processor->callFilter($transactions, getPending: false, notBefore: null, notAfter: null);

        $this->assertCount(2, $result);
        $this->assertSame('10.00', $result[0]->amount);
        $this->assertSame('30.00', $result[1]->amount);
    }

    /**
     * Pending transactions should be included when $getPending is true.
     */
    public function testPendingTransactionsIncludedWhenGetPending(): void
    {
        $processor = $this->createProcessor(pendingCheck: fn ($tx) => $tx->pending);

        $transactions = $this->makeCountableIterator([
            $this->makeTx('10.00', '2025-06-15', false),
            $this->makeTx('20.00', '2025-06-15', true),
        ]);

        $result = $processor->callFilter($transactions, getPending: true, notBefore: null, notAfter: null);

        $this->assertCount(2, $result);
    }

    /**
     * Transactions before $notBefore should be excluded.
     */
    public function testTransactionsBeforeNotBeforeExcluded(): void
    {
        $processor = $this->createProcessor(pendingCheck: fn () => false);

        $transactions = $this->makeCountableIterator([
            $this->makeTx('10.00', '2025-05-01', false),
            $this->makeTx('20.00', '2025-06-15', false),
        ]);

        $result = $processor->callFilter(
            $transactions,
            getPending: true,
            notBefore: Carbon::parse('2025-06-01'),
            notAfter: null
        );

        $this->assertCount(1, $result);
        $this->assertSame('20.00', $result[0]->amount);
    }

    /**
     * Transactions after $notAfter should be excluded.
     */
    public function testTransactionsAfterNotAfterExcluded(): void
    {
        $processor = $this->createProcessor(pendingCheck: fn () => false);

        $transactions = $this->makeCountableIterator([
            $this->makeTx('10.00', '2025-06-15', false),
            $this->makeTx('20.00', '2025-07-15', false),
        ]);

        $result = $processor->callFilter(
            $transactions,
            getPending: true,
            notBefore: null,
            notAfter: Carbon::parse('2025-06-30')
        );

        $this->assertCount(1, $result);
        $this->assertSame('10.00', $result[0]->amount);
    }

    /**
     * Zero-amount transactions should be excluded.
     */
    public function testZeroAmountTransactionsExcluded(): void
    {
        $processor = $this->createProcessor(pendingCheck: fn () => false);

        $transactions = $this->makeCountableIterator([
            $this->makeTx('10.00', '2025-06-15', false),
            $this->makeTx('0', '2025-06-15', false),
            $this->makeTx('0.00', '2025-06-15', false),
        ]);

        $result = $processor->callFilter($transactions, getPending: true, notBefore: null, notAfter: null);

        $this->assertCount(1, $result);
        $this->assertSame('10.00', $result[0]->amount);
    }

    /**
     * Fix #54: LunchFlow uses $transaction->hold for pending status.
     * Ensure the trait correctly delegates to isTransactionPending().
     */
    public function testHoldBasedPendingCheckWorks(): void
    {
        // Simulates LunchFlow behavior where 'hold' = true means pending
        $processor = $this->createProcessor(pendingCheck: fn ($tx) => $tx->pending);

        $transactions = $this->makeCountableIterator([
            $this->makeTx('10.00', '2025-06-15', false),
            $this->makeTx('20.00', '2025-06-15', true),  // hold = true, should be filtered
        ]);

        $result = $processor->callFilter($transactions, getPending: false, notBefore: null, notAfter: null);

        $this->assertCount(1, $result);
        $this->assertSame('10.00', $result[0]->amount);
    }

    /**
     * Fix #54: Verify that EnableBanking's status-based pending check also works.
     */
    public function testStatusBasedPendingCheckWorks(): void
    {
        // Simulates EnableBanking behavior where status = 'pending' means pending
        $processor = $this->createProcessor(pendingCheck: fn ($tx) => 'pending' === ($tx->status ?? ''));

        $tx1 = $this->makeTx('10.00', '2025-06-15', false);
        $tx1->status = 'booked';
        $tx2 = $this->makeTx('20.00', '2025-06-15', false);
        $tx2->status = 'pending';

        $transactions = $this->makeCountableIterator([$tx1, $tx2]);

        $result = $processor->callFilter($transactions, getPending: false, notBefore: null, notAfter: null);

        $this->assertCount(1, $result);
        $this->assertSame('10.00', $result[0]->amount);
    }

    /**
     * Empty transaction set produces empty result.
     */
    public function testEmptyTransactionSetReturnsEmpty(): void
    {
        $processor = $this->createProcessor(pendingCheck: fn () => false);

        $transactions = $this->makeCountableIterator([]);

        $result = $processor->callFilter($transactions, getPending: true, notBefore: null, notAfter: null);

        $this->assertCount(0, $result);
    }

    /**
     * Create a simple transaction-like object.
     */
    private function makeTx(string $amount, string $date, bool $pending): object
    {
        return new class ($amount, $date, $pending) {
            public string $amount;
            public string $status = '';
            public bool $pending;
            private Carbon $date;

            public function __construct(string $amount, string $date, bool $pending)
            {
                $this->amount  = $amount;
                $this->pending = $pending;
                $this->date    = Carbon::parse($date);
            }

            public function getDate(): Carbon
            {
                return $this->date;
            }

            public function getDescription(): string
            {
                return 'test description';
            }
        };
    }

    /**
     * Wrap array in a countable iterator for the trait.
     */
    private function makeCountableIterator(array $items): \ArrayIterator
    {
        return new \ArrayIterator($items);
    }

    /**
     * Create a test processor using the trait with a configurable pending check.
     */
    private function createProcessor(\Closure $pendingCheck): object
    {
        return new class ($pendingCheck) {
            use TransactionFilterTrait;

            private \Closure $pendingCheck;
            private array $warnings = [];

            public function __construct(\Closure $pendingCheck)
            {
                $this->pendingCheck = $pendingCheck;
                bcscale(12);
            }

            public function callFilter(
                iterable $transactions,
                bool $getPending,
                ?Carbon $notBefore,
                ?Carbon $notAfter,
            ): array {
                return $this->filterTransactionSet($transactions, $getPending, $notBefore, $notAfter);
            }

            private function isTransactionPending(object $transaction): bool
            {
                return ($this->pendingCheck)($transaction);
            }

            private function getTransactionAmount(object $transaction): string
            {
                return $transaction->amount;
            }

            private function addZeroAmountWarning(object $transaction): void
            {
                $this->warnings[] = $transaction;
            }

            public function getWarnings(): array
            {
                return $this->warnings;
            }
        };
    }
}
