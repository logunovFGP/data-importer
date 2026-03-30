<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BasisBank\Request;

use App\Services\BasisBank\Request\GetTransactionsRequest;
use PHPUnit\Framework\TestCase;

/**
 * Tests for fix #42: static $webSessionRowsCache clearCache() method.
 */
class GetTransactionsRequestCacheTest extends TestCase
{
    public function testClearCacheMethodExists(): void
    {
        $this->assertTrue(
            method_exists(GetTransactionsRequest::class, 'clearCache'),
            'GetTransactionsRequest must have a public static clearCache() method'
        );
    }

    public function testClearCacheIsCallable(): void
    {
        // Should not throw — verifies the method can be called without side effects.
        GetTransactionsRequest::clearCache();
        $this->assertTrue(true);
    }

    public function testClearCacheResetsStaticProperty(): void
    {
        // Use reflection to verify the static property is empty after clearing.
        $reflection = new \ReflectionClass(GetTransactionsRequest::class);
        $property = $reflection->getProperty('webSessionRowsCache');

        // Seed the cache with a value via reflection.
        $property->setValue(null, ['testKey' => [['id' => 1]]]);
        $this->assertNotEmpty($property->getValue(null), 'Cache should be seeded');

        GetTransactionsRequest::clearCache();

        $this->assertSame([], $property->getValue(null), 'Cache must be empty after clearCache()');
    }
}
