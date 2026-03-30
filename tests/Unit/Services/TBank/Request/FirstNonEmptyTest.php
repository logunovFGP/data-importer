<?php

declare(strict_types=1);

namespace Tests\Unit\Services\TBank\Request;

use App\Services\TBank\Request\GetAccountsRequest;
use App\Services\TBank\Request\GetTransactionsRequest;
use App\Services\TBank\Request\SessionJsonRequest;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Fix #24: Verify that firstNonEmpty() is provided by SessionJsonRequest
 * and works correctly when called from child classes.
 *
 * @internal
 *
 * @coversDefaultClass \App\Services\TBank\Request\SessionJsonRequest
 */
final class FirstNonEmptyTest extends TestCase
{
    /**
     * @covers ::firstNonEmpty
     */
    public function testReturnsFirstNonEmptyValue(): void
    {
        $method = $this->getFirstNonEmptyMethod();
        $instance = $this->createGetAccountsRequest();

        $result = $method->invoke($instance, [null, '', '  ', 'hello', 'world']);

        $this->assertSame('hello', $result);
    }

    /**
     * @covers ::firstNonEmpty
     */
    public function testReturnsDefaultWhenAllEmpty(): void
    {
        $method = $this->getFirstNonEmptyMethod();
        $instance = $this->createGetAccountsRequest();

        $result = $method->invoke($instance, [null, '', '  '], 'default-value');

        $this->assertSame('default-value', $result);
    }

    /**
     * @covers ::firstNonEmpty
     */
    public function testReturnsEmptyStringWhenAllEmptyAndNoDefault(): void
    {
        $method = $this->getFirstNonEmptyMethod();
        $instance = $this->createGetAccountsRequest();

        $result = $method->invoke($instance, [null, '', '  ']);

        $this->assertSame('', $result);
    }

    /**
     * @covers ::firstNonEmpty
     */
    public function testTrimsWhitespace(): void
    {
        $method = $this->getFirstNonEmptyMethod();
        $instance = $this->createGetAccountsRequest();

        $result = $method->invoke($instance, ['  trimmed  ']);

        $this->assertSame('trimmed', $result);
    }

    /**
     * @covers ::firstNonEmpty
     */
    public function testHandlesEmptyArray(): void
    {
        $method = $this->getFirstNonEmptyMethod();
        $instance = $this->createGetAccountsRequest();

        $result = $method->invoke($instance, []);

        $this->assertSame('', $result);
    }

    /**
     * Verify that both child classes (GetAccountsRequest, GetTransactionsRequest)
     * can use firstNonEmpty from the parent without their own copy.
     *
     * @covers ::firstNonEmpty
     */
    public function testAccessibleFromGetTransactionsRequest(): void
    {
        $instance = new GetTransactionsRequest('fake-session', 'fake-cookie', 'fake-account');
        $method   = new ReflectionMethod(GetTransactionsRequest::class, 'firstNonEmpty');

        $result = $method->invoke($instance, [null, 'found']);

        $this->assertSame('found', $result);
    }

    /**
     * Verify the method is defined on SessionJsonRequest (the parent class).
     */
    public function testMethodExistsOnParentClass(): void
    {
        $this->assertTrue(
            method_exists(SessionJsonRequest::class, 'firstNonEmpty'),
            'firstNonEmpty() must be defined on SessionJsonRequest'
        );
    }

    private function getFirstNonEmptyMethod(): ReflectionMethod
    {
        return new ReflectionMethod(GetAccountsRequest::class, 'firstNonEmpty');
    }

    private function createGetAccountsRequest(): GetAccountsRequest
    {
        return new GetAccountsRequest('fake-session', 'fake-cookie');
    }
}
