<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Shared\Request;

use App\Services\Shared\Request\PostCurrencyRequest;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Fix #56: PostCurrencyRequest unsupported methods throw BadMethodCallException.
 *
 * @internal
 * @coversDefaultClass \App\Services\Shared\Request\PostCurrencyRequest
 */
final class PostCurrencyRequestTest extends TestCase
{
    private PostCurrencyRequest $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->request = (new \ReflectionClass(PostCurrencyRequest::class))->newInstanceWithoutConstructor();
    }

    public function testGetThrowsBadMethodCallException(): void
    {
        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Method not supported for PostCurrencyRequest');
        $this->request->get();
    }

    public function testPutThrowsBadMethodCallException(): void
    {
        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Method not supported for PostCurrencyRequest');
        $this->request->put();
    }

    public function testDeleteThrowsBadMethodCallException(): void
    {
        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Method not supported for PostCurrencyRequest');
        $this->request->delete();
    }
}
