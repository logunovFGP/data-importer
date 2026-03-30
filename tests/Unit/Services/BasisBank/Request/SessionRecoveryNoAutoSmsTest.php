<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BasisBank\Request;

use App\Exceptions\ImporterHttpException;
use App\Services\BasisBank\Request\GetAccountsRequest;
use App\Services\BasisBank\Request\GetTransactionsRequest;
use Tests\TestCase;

/**
 * Tests for Fix #13: Session recovery must throw instead of auto-triggering SMS.
 *
 * Verifies that recoverWebSessionForCardModule() in both GetAccountsRequest and
 * GetTransactionsRequest immediately throws an ImporterHttpException instead of
 * calling BasisBankWebAuthClient::start() (which may trigger SMS OTP).
 *
 * @internal
 * @coversNothing
 */
final class SessionRecoveryNoAutoSmsTest extends TestCase
{
    /**
     * Invoke the private recoverWebSessionForCardModule method via reflection.
     */
    private function callRecoverWebSession(object $request, string $funq, ImporterHttpException $trigger): void
    {
        $reflection = new \ReflectionMethod($request, 'recoverWebSessionForCardModule');
        $reflection->setAccessible(true);
        $reflection->invoke($request, $funq, $trigger);
    }

    public function testGetAccountsRequestThrowsOnSessionRecovery(): void
    {
        $request = new GetAccountsRequest('test-token', 'test-consent');

        $trigger = new ImporterHttpException('Session expired');
        $trigger->statusCode = 401;

        $this->expectException(ImporterHttpException::class);
        $this->expectExceptionMessageMatches('/session has expired.*re-authenticate/i');

        $this->callRecoverWebSession($request, 'balance-page', $trigger);
    }

    public function testGetTransactionsRequestThrowsOnSessionRecovery(): void
    {
        $request = new GetTransactionsRequest('test-token', 'test-consent', 'test-account');

        $trigger = new ImporterHttpException('Session expired');
        $trigger->statusCode = 401;

        $this->expectException(ImporterHttpException::class);
        $this->expectExceptionMessageMatches('/session has expired.*re-authenticate/i');

        $this->callRecoverWebSession($request, 'statement-ACC123', $trigger);
    }

    public function testExceptionPreservesOriginalStatusCode(): void
    {
        $request = new GetAccountsRequest('test-token', 'test-consent');

        $trigger = new ImporterHttpException('Dead session');
        $trigger->statusCode = 403;

        try {
            $this->callRecoverWebSession($request, 'info-page', $trigger);
            $this->fail('Expected ImporterHttpException to be thrown.');
        } catch (ImporterHttpException $e) {
            $this->assertSame(403, $e->statusCode, 'Thrown exception must preserve the original status code.');
            $this->assertStringContainsString('info-page', $e->getMessage());
            $this->assertStringContainsString('silent SMS/OTP', $e->getMessage());
        }
    }

    public function testExceptionMessageContainsFunqIdentifier(): void
    {
        $request = new GetTransactionsRequest('test-token', 'test-consent', 'test-account');

        $trigger = new ImporterHttpException('Session dead');
        $trigger->statusCode = 500;

        try {
            $this->callRecoverWebSession($request, 'statement-MY-CARD-42', $trigger);
            $this->fail('Expected ImporterHttpException to be thrown.');
        } catch (ImporterHttpException $e) {
            $this->assertStringContainsString(
                'statement-MY-CARD-42',
                $e->getMessage(),
                'Exception message must include the funq identifier for debugging.'
            );
        }
    }
}
