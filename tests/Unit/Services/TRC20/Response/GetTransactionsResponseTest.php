<?php

declare(strict_types=1);

namespace Tests\Unit\Services\TRC20\Response;

use App\Services\TRC20\Response\GetTransactionsResponse;
use Tests\TestCase;

/**
 * Tests for Fix #64 (processData idempotency) and Fix #65 (current() nullable return).
 *
 * @internal
 * @coversDefaultClass \App\Services\TRC20\Response\GetTransactionsResponse
 */
final class GetTransactionsResponseTest extends TestCase
{
    private function buildRow(string $id = 'tx1', string $amount = '100.00'): array
    {
        return [
            'id'          => $id,
            'accountId'   => 'TWALLET1',
            'amount'      => $amount,
            'currency'    => 'USDT',
            'date'        => '2025-06-01',
            'description' => 'Test TRC20 tx',
            'merchant'    => '',
            'hold'        => false,
        ];
    }

    public function testProcessDataIsIdempotent(): void
    {
        $response = new GetTransactionsResponse([$this->buildRow()]);

        $response->processData();
        $this->assertSame(1, $response->count());

        // Calling processData again should not duplicate items
        $response->processData();
        $this->assertSame(1, $response->count());
    }

    public function testProcessDataMultipleRowsIdempotent(): void
    {
        $response = new GetTransactionsResponse([
            $this->buildRow('tx1', '10.00'),
            $this->buildRow('tx2', '20.00'),
        ]);

        $response->processData();
        $this->assertSame(2, $response->count());

        $response->processData();
        $this->assertSame(2, $response->count());
    }

    public function testCurrentReturnsNullWhenEmpty(): void
    {
        $response = new GetTransactionsResponse([]);
        $response->processData();

        // No items — current() should return null
        $this->assertNull($response->current());
    }

    public function testCurrentReturnsTransactionAfterProcessing(): void
    {
        $response = new GetTransactionsResponse([$this->buildRow()]);
        $response->processData();

        $tx = $response->current();
        $this->assertNotNull($tx);
        $this->assertSame('tx1', $tx->id);
    }

    public function testIteratorWalksAllItems(): void
    {
        $response = new GetTransactionsResponse([
            $this->buildRow('tx1'),
            $this->buildRow('tx2'),
            $this->buildRow('tx3'),
        ]);
        $response->processData();

        $ids = [];
        foreach ($response as $tx) {
            $ids[] = $tx->id;
        }

        $this->assertSame(['tx1', 'tx2', 'tx3'], $ids);
    }

    public function testValidReturnsFalseWhenBeyondEnd(): void
    {
        $response = new GetTransactionsResponse([$this->buildRow()]);
        $response->processData();

        $response->next(); // position = 1, only 1 item
        $this->assertFalse($response->valid());
    }

    public function testCountBeforeProcessDataReturnsZero(): void
    {
        $response = new GetTransactionsResponse([$this->buildRow()]);

        // Before processData, collection is empty
        $this->assertSame(0, $response->count());
    }
}
