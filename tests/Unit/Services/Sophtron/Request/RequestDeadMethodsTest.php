<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Sophtron\Request;

use App\Services\Shared\Response\Response;
use App\Services\Sophtron\Request\Request;
use Tests\TestCase;

/**
 * @internal
 *
 * @coversDefaultClass \App\Services\Sophtron\Request\Request
 */
final class RequestDeadMethodsTest extends TestCase
{
    /**
     * Fix #19: authenticatedJsonPost must throw BadMethodCallException (not ImporterHttpException).
     *
     * @covers ::authenticatedJsonPost
     */
    public function testAuthenticatedJsonPostThrowsBadMethodCallException(): void
    {
        $request = $this->createConcreteRequest();

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Not implemented for Sophtron provider');

        // Use reflection to call the protected method.
        $method = new \ReflectionMethod($request, 'authenticatedJsonPost');
        $method->setAccessible(true);
        $method->invoke($request, ['test' => 'data']);
    }

    /**
     * Fix #19: formatTime must throw BadMethodCallException (not ImporterErrorException).
     *
     * @covers ::formatTime
     */
    public function testFormatTimeThrowsBadMethodCallException(): void
    {
        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Not implemented for Sophtron provider');

        Request::formatTime(300);
    }

    /**
     * Fix #19: logRateLimitHeaders must throw BadMethodCallException.
     *
     * @covers ::logRateLimitHeaders
     */
    public function testLogRateLimitHeadersThrowsBadMethodCallException(): void
    {
        $request  = $this->createConcreteRequest();
        $response = $this->createMock(\Psr\Http\Message\ResponseInterface::class);

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Not implemented for Sophtron provider');

        $method = new \ReflectionMethod($request, 'logRateLimitHeaders');
        $method->setAccessible(true);
        $method->invoke($request, $response, false);
    }

    /**
     * Fix #19: pauseForRateLimit must throw BadMethodCallException.
     *
     * @covers ::pauseForRateLimit
     */
    public function testPauseForRateLimitThrowsBadMethodCallException(): void
    {
        $request  = $this->createConcreteRequest();
        $response = $this->createMock(\Psr\Http\Message\ResponseInterface::class);

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Not implemented for Sophtron provider');

        $method = new \ReflectionMethod($request, 'pauseForRateLimit');
        $method->setAccessible(true);
        $method->invoke($request, $response, false);
    }

    /**
     * Fix #19: reportAndPause must throw BadMethodCallException.
     *
     * @covers ::reportAndPause
     */
    public function testReportAndPauseThrowsBadMethodCallException(): void
    {
        $request = $this->createConcreteRequest();

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Not implemented for Sophtron provider');

        $method = new \ReflectionMethod($request, 'reportAndPause');
        $method->setAccessible(true);
        $method->invoke($request, 'Rate limit', 5, 300, false);
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
