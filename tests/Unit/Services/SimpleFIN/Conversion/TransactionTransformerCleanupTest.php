<?php

declare(strict_types=1);

namespace Tests\Unit\Services\SimpleFIN\Conversion;

use App\Services\SimpleFIN\Conversion\TransactionTransformer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests for Fix #69: Duplicated cleanup regex patterns extracted to CLEANUP_PATTERNS constant.
 *
 * Verifies that both extractCounterAccountName() and normalizeForMatching()
 * use the shared constant and produce consistent results.
 *
 * @internal
 * @coversDefaultClass \App\Services\SimpleFIN\Conversion\TransactionTransformer
 */
final class TransactionTransformerCleanupTest extends TestCase
{
    private TransactionTransformer $transformer;
    private ReflectionMethod $extractCounterAccountName;
    private ReflectionMethod $normalizeForMatching;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transformer              = new TransactionTransformer();
        $this->extractCounterAccountName = new ReflectionMethod(TransactionTransformer::class, 'extractCounterAccountName');
        $this->extractCounterAccountName->setAccessible(true);
        $this->normalizeForMatching     = new ReflectionMethod(TransactionTransformer::class, 'normalizeForMatching');
        $this->normalizeForMatching->setAccessible(true);
    }

    public function testCleanupPatternsConstantExists(): void
    {
        $ref = new \ReflectionClass(TransactionTransformer::class);
        $this->assertTrue($ref->hasConstant('CLEANUP_PATTERNS'), 'CLEANUP_PATTERNS constant should exist');
        $this->assertIsArray($ref->getConstant('CLEANUP_PATTERNS'));
        $this->assertNotEmpty($ref->getConstant('CLEANUP_PATTERNS'));
    }

    #[DataProvider('prefixDescriptionProvider')]
    public function testExtractCounterAccountNameStripsPrefix(string $input, string $expectedContains): void
    {
        $result = $this->extractCounterAccountName->invoke($this->transformer, $input);
        $this->assertStringContainsString($expectedContains, $result);
        $this->assertStringNotContainsString('PAYMENT', strtoupper(substr($result, 0, 7)));
    }

    public static function prefixDescriptionProvider(): iterable
    {
        yield 'PAYMENT prefix' => ['PAYMENT Acme Corp', 'Acme Corp'];
        yield 'DEPOSIT prefix' => ['DEPOSIT BigStore', 'BigStore'];
        yield 'TRANSFER prefix' => ['TRANSFER MyBank', 'MyBank'];
        yield 'DEBIT prefix' => ['DEBIT SomeMerchant', 'SomeMerchant'];
        yield 'CREDIT prefix' => ['CREDIT Revenue Source', 'Revenue Source'];
    }

    public function testExtractCounterAccountNameStripsSuffix(): void
    {
        $result = $this->extractCounterAccountName->invoke($this->transformer, 'Acme Corp PAYMENT');
        $this->assertStringNotContainsString('PAYMENT', $result);
    }

    public function testExtractCounterAccountNameStripsFromTo(): void
    {
        $result = $this->extractCounterAccountName->invoke($this->transformer, 'FROM SomeAccount');
        $this->assertStringNotContainsString('FROM', strtoupper($result));
    }

    public function testExtractCounterAccountNameStripsTrailingDate(): void
    {
        $result = $this->extractCounterAccountName->invoke($this->transformer, 'Acme Corp 2025-03-15 extra');
        $this->assertStringNotContainsString('2025-03-15', $result);
    }

    public function testNormalizeForMatchingStripsPrefix(): void
    {
        $result = $this->normalizeForMatching->invoke($this->transformer, 'PAYMENT Acme Corp');
        $this->assertStringContainsString('acme', $result);
    }

    public function testNormalizeForMatchingStripsReferenceNumbers(): void
    {
        $result = $this->normalizeForMatching->invoke($this->transformer, 'Acme Corp #REF123 extra');
        $this->assertStringNotContainsString('ref123', $result);
    }

    public function testBothMethodsStripSameBasePrefixes(): void
    {
        $input   = 'PAYMENT Some Merchant';
        $extract = $this->extractCounterAccountName->invoke($this->transformer, $input);
        $normal  = $this->normalizeForMatching->invoke($this->transformer, $input);

        // Both should have removed "PAYMENT" prefix
        $this->assertStringNotContainsString('PAYMENT', strtoupper($extract));
        $this->assertStringNotContainsString('payment', $normal);
    }

    public function testEmptyDescriptionFallsBackToUnknown(): void
    {
        $result = $this->extractCounterAccountName->invoke($this->transformer, '');
        $this->assertSame('Unknown', $result);
    }

    public function testLongDescriptionIsTruncated(): void
    {
        $long   = str_repeat('A', 150);
        $result = $this->extractCounterAccountName->invoke($this->transformer, $long);
        $this->assertLessThanOrEqual(100, strlen($result));
        $this->assertStringEndsWith('...', $result);
    }
}
