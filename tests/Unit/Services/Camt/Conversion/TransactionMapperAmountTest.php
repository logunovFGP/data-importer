<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Camt\Conversion;

use App\Services\Camt\Conversion\TransactionMapper;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Tests for Fix #49 + #68: processAmount() uses bccomp instead of float casts.
 *
 * @internal
 * @coversDefaultClass \App\Services\Camt\Conversion\TransactionMapper
 */
final class TransactionMapperAmountTest extends TestCase
{
    private ReflectionMethod $processAmount;
    private ReflectionMethod $bcAbs;
    private TransactionMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        bcscale(12);

        // TransactionMapper constructor depends on Configuration and makes API calls.
        // We create an instance without invoking the constructor for unit-testing private methods.
        $this->mapper         = (new \ReflectionClass(TransactionMapper::class))->newInstanceWithoutConstructor();
        $this->processAmount  = new ReflectionMethod(TransactionMapper::class, 'processAmount');
        $this->processAmount->setAccessible(true);
        $this->bcAbs          = new ReflectionMethod(TransactionMapper::class, 'bcAbs');
        $this->bcAbs->setAccessible(true);
    }

    public function testBcAbsPositiveValue(): void
    {
        $result = $this->bcAbs->invoke($this->mapper, '12.50');
        $this->assertSame(0, bccomp('12.50', $result));
    }

    public function testBcAbsNegativeValue(): void
    {
        $result = $this->bcAbs->invoke($this->mapper, '-42.75');
        $this->assertSame(0, bccomp('42.75', $result));
    }

    public function testBcAbsZero(): void
    {
        $result = $this->bcAbs->invoke($this->mapper, '0');
        $this->assertSame(0, bccomp('0', $result));
    }

    public function testProcessAmountGroupPicksLargestAbsoluteValue(): void
    {
        $data = ['data' => ['10.00', '-25.00', '5.00']];
        $result = $this->processAmount->invoke($this->mapper, 'group', $data);
        $this->assertSame(0, bccomp('-25.00', $result), 'Expected the value with the largest absolute magnitude');
    }

    public function testProcessAmountSinglePicksLargestAbsoluteValue(): void
    {
        $data = ['data' => ['3.50', '-7.25']];
        $result = $this->processAmount->invoke($this->mapper, 'single', $data);
        $this->assertSame(0, bccomp('-7.25', $result), 'Expected the value with the largest absolute magnitude');
    }

    public function testProcessAmountSkipsNullValues(): void
    {
        $data = ['data' => [null, '15.00', null]];
        $result = $this->processAmount->invoke($this->mapper, 'group', $data);
        $this->assertSame(0, bccomp('15.00', $result));
    }

    public function testProcessAmountAllNullReturnsZero(): void
    {
        $data = ['data' => [null, null]];
        $result = $this->processAmount->invoke($this->mapper, 'group', $data);
        $this->assertSame('0', $result);
    }

    public function testProcessAmountReturnsString(): void
    {
        $data = ['data' => ['42.99']];
        $result = $this->processAmount->invoke($this->mapper, 'group', $data);
        $this->assertIsString($result);
    }

    public function testProcessAmountHighPrecisionNoFloatLoss(): void
    {
        // A value that would lose precision with float conversion
        $data = ['data' => ['99999999999.123456']];
        $result = $this->processAmount->invoke($this->mapper, 'group', $data);
        $this->assertSame('99999999999.123456', $result);
    }

    public function testProcessAmountEmptyDataReturnsZero(): void
    {
        $data = ['data' => []];
        $result = $this->processAmount->invoke($this->mapper, 'group', $data);
        $this->assertSame('0', $result);
    }
}
