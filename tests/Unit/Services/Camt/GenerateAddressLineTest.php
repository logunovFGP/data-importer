<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Camt;

use App\Services\Camt\AbstractTransaction;
use Genkgo\Camt\DTO\Address;
use ReflectionMethod;
use Tests\TestCase;

/**
 * @internal
 *
 * @coversDefaultClass \App\Services\Camt\AbstractTransaction
 */
final class GenerateAddressLineTest extends TestCase
{
    /**
     * Fix #10: generateAddressLine(null) must return empty string without crashing.
     * Previously, passing null called ->getAddressLines() on null, causing a fatal error.
     *
     * @covers ::generateAddressLine
     */
    public function testNullAddressReturnsEmptyString(): void
    {
        // Create a minimal concrete stub of the abstract class so we can call the private method.
        $concreteStub    = new class () extends AbstractTransaction {};

        $method          = new ReflectionMethod(AbstractTransaction::class, 'generateAddressLine');

        $result          = $method->invoke($concreteStub, null);

        $this->assertSame('', $result, 'generateAddressLine(null) must return empty string');
    }

    /**
     * When a valid Address is provided, generateAddressLine should return joined lines.
     *
     * @covers ::generateAddressLine
     */
    public function testValidAddressReturnsJoinedLines(): void
    {
        $concreteStub = new class () extends AbstractTransaction {};

        $address      = new Address();
        $address = $address->addAddressLine('123 Main St');
        $address = $address->addAddressLine('Suite 100');

        $method       = new ReflectionMethod(AbstractTransaction::class, 'generateAddressLine');

        $result       = $method->invoke($concreteStub, $address);

        $this->assertSame('123 Main St, Suite 100', $result);
    }
}
