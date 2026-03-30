<?php

declare(strict_types=1);

namespace Tests\Unit\Services\TRC20\Request;

use App\Services\TRC20\Request\GetTransactionsRequest;
use App\Services\TRC20\Request\GetTrxTransactionsRequest;
use App\Services\TRC20\Request\GetWalletRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Fix #22: Verify that requestHeaders() is provided by TRC20RequestTrait
 * and works identically in all three TRC20 request classes.
 *
 * @internal
 */
final class TRC20RequestHeadersTest extends TestCase
{
    #[DataProvider('requestClassProvider')]
    public function testRequestHeadersWithApiKeyReturnsHeader(string $className, array $constructorArgs): void
    {
        $instance = new $className(...$constructorArgs);
        $method   = new ReflectionMethod($className, 'requestHeaders');

        $headers = $method->invoke($instance);

        $this->assertArrayHasKey('TRON-PRO-API-KEY', $headers);
        $this->assertSame('test-api-key-123', $headers['TRON-PRO-API-KEY']);
    }

    #[DataProvider('emptyApiKeyClassProvider')]
    public function testRequestHeadersWithEmptyApiKeyReturnsEmptyArray(string $className, array $constructorArgs): void
    {
        $instance = new $className(...$constructorArgs);
        $method   = new ReflectionMethod($className, 'requestHeaders');

        $headers = $method->invoke($instance);

        $this->assertArrayNotHasKey('TRON-PRO-API-KEY', $headers);
        $this->assertSame([], $headers);
    }

    #[DataProvider('whitespaceApiKeyClassProvider')]
    public function testRequestHeadersWithWhitespaceApiKeyReturnsEmptyArray(string $className, array $constructorArgs): void
    {
        $instance = new $className(...$constructorArgs);
        $method   = new ReflectionMethod($className, 'requestHeaders');

        $headers = $method->invoke($instance);

        $this->assertArrayNotHasKey('TRON-PRO-API-KEY', $headers);
        $this->assertSame([], $headers);
    }

    public static function requestClassProvider(): array
    {
        return [
            'GetTransactionsRequest'    => [GetTransactionsRequest::class, ['test-api-key-123', ['TWallet1']]],
            'GetTrxTransactionsRequest' => [GetTrxTransactionsRequest::class, ['test-api-key-123', ['TWallet1']]],
            'GetWalletRequest'          => [GetWalletRequest::class, ['test-api-key-123', 'TWallet1']],
        ];
    }

    public static function emptyApiKeyClassProvider(): array
    {
        return [
            'GetTransactionsRequest'    => [GetTransactionsRequest::class, ['', ['TWallet1']]],
            'GetTrxTransactionsRequest' => [GetTrxTransactionsRequest::class, ['', ['TWallet1']]],
            'GetWalletRequest'          => [GetWalletRequest::class, ['', 'TWallet1']],
        ];
    }

    public static function whitespaceApiKeyClassProvider(): array
    {
        return [
            'GetTransactionsRequest'    => [GetTransactionsRequest::class, ['   ', ['TWallet1']]],
            'GetTrxTransactionsRequest' => [GetTrxTransactionsRequest::class, ['   ', ['TWallet1']]],
            'GetWalletRequest'          => [GetWalletRequest::class, ['   ', 'TWallet1']],
        ];
    }
}
