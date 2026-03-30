<?php

declare(strict_types=1);

namespace Tests\Unit\Services\TBank;

use App\Services\TBank\Request\GetTransactionsRequest;
use ReflectionMethod;
use Tests\TestCase;

/**
 * @internal
 *
 * @coversDefaultClass \App\Services\TBank\Request\GetTransactionsRequest
 */
final class GetTransactionsRequestExtractAmountTest extends TestCase
{
    private ReflectionMethod $extractAmount;
    private GetTransactionsRequest $request;

    protected function setUp(): void
    {
        parent::setUp();

        // Construct with dummy values -- we only test the private extractAmount() method via reflection.
        $this->request       = new GetTransactionsRequest('fake-session', 'fake-cookie', 'fake-account');
        $this->extractAmount = new ReflectionMethod(GetTransactionsRequest::class, 'extractAmount');
    }

    /**
     * Fix #4: When no direction indicator (isIncome, isDebit, sign, operationType, type) is present,
     * the raw amount must be returned as-is. Previously, positive amounts were forced negative.
     *
     * @covers ::extractAmount
     */
    public function testPositiveAmountWithNoDirectionIndicatorStaysPositive(): void
    {
        $row    = ['amount' => 5000.0];
        $result = $this->extractAmount->invoke($this->request, $row);

        $this->assertSame(5000.0, $result, 'Positive amount without direction indicator must stay positive');
    }

    /**
     * Negative amounts without direction indicator should also be returned as-is.
     *
     * @covers ::extractAmount
     */
    public function testNegativeAmountWithNoDirectionIndicatorStaysNegative(): void
    {
        $row    = ['amount' => -3200.0];
        $result = $this->extractAmount->invoke($this->request, $row);

        $this->assertSame(-3200.0, $result, 'Negative amount without direction indicator must stay negative');
    }

    /**
     * Explicit isIncome=true should return positive regardless of raw sign.
     *
     * @covers ::extractAmount
     */
    public function testIsIncomeTrueReturnsPositive(): void
    {
        $row    = ['amount' => -1500.0, 'isIncome' => true];
        $result = $this->extractAmount->invoke($this->request, $row);

        $this->assertSame(1500.0, $result, 'isIncome=true must return positive amount');
    }

    /**
     * Explicit isDebit=true should return negative regardless of raw sign.
     *
     * @covers ::extractAmount
     */
    public function testIsDebitTrueReturnsNegative(): void
    {
        $row    = ['amount' => 800.0, 'isDebit' => true];
        $result = $this->extractAmount->invoke($this->request, $row);

        $this->assertSame(-800.0, $result, 'isDebit=true must return negative amount');
    }

    /**
     * Sign field containing "debit" should return negative.
     *
     * @covers ::extractAmount
     */
    public function testSignDebitReturnsNegative(): void
    {
        $row    = ['amount' => 250.0, 'sign' => 'Debit'];
        $result = $this->extractAmount->invoke($this->request, $row);

        $this->assertSame(-250.0, $result, 'sign=Debit must return negative amount');
    }

    /**
     * Sign field containing "credit" should return positive.
     *
     * @covers ::extractAmount
     */
    public function testSignCreditReturnsPositive(): void
    {
        $row    = ['amount' => -750.0, 'sign' => 'credit'];
        $result = $this->extractAmount->invoke($this->request, $row);

        $this->assertSame(750.0, $result, 'sign=credit must return positive amount');
    }

    /**
     * Zero amount without direction indicator should return zero.
     *
     * @covers ::extractAmount
     */
    public function testZeroAmountReturnsZero(): void
    {
        $row    = ['amount' => 0.0];
        $result = $this->extractAmount->invoke($this->request, $row);

        $this->assertSame(0.0, $result, 'Zero amount must remain zero');
    }
}
