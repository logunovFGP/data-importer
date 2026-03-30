<?php

declare(strict_types=1);

namespace Tests\Unit\Services\LunchFlow\Request;

use App\Services\LunchFlow\Request\Request;
use App\Services\Shared\Response\Response;
use Tests\TestCase;

/**
 * @internal
 *
 * @coversDefaultClass \App\Services\LunchFlow\Request\Request
 */
final class RequestParametersTest extends TestCase
{
    /**
     * Fix #17: $parameters was used in setParameters() but never declared as a class property.
     * In PHP 8.2+ this triggers a deprecation notice for dynamic properties.
     * After the fix, $parameters is a declared private array property with default [].
     *
     * @covers ::setParameters
     */
    public function testSetParametersStoresValues(): void
    {
        $request    = $this->createConcreteRequest();
        $params     = ['page' => '2', 'limit' => '50'];

        $request->setParameters($params);

        $reflection = new \ReflectionProperty(Request::class, 'parameters');
        $reflection->setAccessible(true);

        $this->assertSame($params, $reflection->getValue($request), 'setParameters() must store the array in the declared $parameters property');
    }

    /**
     * The default parameters value should be an empty array (no dynamic property).
     *
     * @covers ::setParameters
     */
    public function testDefaultParametersIsEmptyArray(): void
    {
        $request    = $this->createConcreteRequest();

        $reflection = new \ReflectionProperty(Request::class, 'parameters');
        $reflection->setAccessible(true);

        $this->assertSame([], $reflection->getValue($request), 'Default parameters must be an empty array');
    }

    /**
     * Fix #17: authenticatedGet() must append query parameters to the URL when non-empty.
     * We verify by checking the $parameters property is declared and that the class
     * has the property as a typed array (not a dynamic property).
     */
    public function testParametersPropertyIsDeclared(): void
    {
        $reflection = new \ReflectionClass(Request::class);

        $this->assertTrue(
            $reflection->hasProperty('parameters'),
            'Request class must have a declared $parameters property'
        );

        $prop = $reflection->getProperty('parameters');
        $this->assertTrue($prop->hasType(), '$parameters property must have a type declaration');
        $this->assertSame('array', $prop->getType()->getName(), '$parameters property must be typed as array');
    }

    /**
     * Fix #18: getDefaultHeaders() was dead code from Spectre copy-paste.
     * Verify it has been removed from the LunchFlow Request class.
     */
    public function testGetDefaultHeadersMethodDoesNotExist(): void
    {
        $reflection = new \ReflectionClass(Request::class);

        $this->assertFalse(
            $reflection->hasMethod('getDefaultHeaders'),
            'getDefaultHeaders() must be removed — it was dead code from Spectre copy-paste'
        );
    }

    /**
     * Create a minimal concrete implementation of the abstract Request class for testing.
     */
    private function createConcreteRequest(): Request
    {
        return new class () extends Request {
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
