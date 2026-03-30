<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Sophtron;

use App\Services\Shared\Response\Response;
use App\Services\Sophtron\Request\Request;
use Tests\TestCase;

/**
 * @internal
 *
 * @coversDefaultClass \App\Services\Sophtron\Request\Request
 */
final class RequestTokenPropertyTest extends TestCase
{
    /**
     * Fix #14: $token was used via getToken()/setToken() but never declared as a class property.
     * In PHP 8.2+ this triggers a deprecation notice for dynamic properties.
     * After the fix, $token is a declared private string property.
     *
     * @covers ::getToken
     * @covers ::setToken
     */
    public function testSetAndGetTokenReturnsStoredValue(): void
    {
        $request = $this->createConcreteRequest();

        $request->setToken('test123');

        $this->assertSame('test123', $request->getToken(), 'getToken() must return the value set via setToken()');
    }

    /**
     * The default token value should be an empty string.
     *
     * @covers ::getToken
     */
    public function testDefaultTokenIsEmptyString(): void
    {
        $request = $this->createConcreteRequest();

        $this->assertSame('', $request->getToken(), 'Default token must be empty string');
    }

    /**
     * Create a minimal concrete implementation of the abstract Request class for testing.
     */
    private function createConcreteRequest(): Request
    {
        return new class () extends Request {
            public string $userId    = 'test-user';
            public string $accessKey = '';
            public string $url       = '/test';

            public function get(): Response
            {
                throw new \RuntimeException('Not implemented for test');
            }

            public function post(): Response
            {
                throw new \RuntimeException('Not implemented for test');
            }

            public function put(): Response
            {
                throw new \RuntimeException('Not implemented for test');
            }
        };
    }
}
