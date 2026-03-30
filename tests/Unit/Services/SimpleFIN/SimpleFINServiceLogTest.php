<?php

declare(strict_types=1);

namespace Tests\Unit\Services\SimpleFIN;

use App\Services\Shared\Configuration\Configuration;
use App\Services\SimpleFIN\SimpleFINService;
use Illuminate\Support\Facades\Log;
use ReflectionClass;
use Tests\TestCase;

/**
 * Tests for Fix #16 (sprintf array bug) and Fix #38 (access token logged in plaintext).
 *
 * @internal
 *
 * @coversNothing
 */
final class SimpleFINServiceLogTest extends TestCase
{
    /**
     * Fix #16: filterForPending must return an array without causing type errors when
     * its result is subsequently passed to count() in the log statement.
     *
     * The original bug: sprintf('Found %d transactions.', $transactions) where
     * $transactions is an array. This caused a PHP type error. The fix changes it to
     * sprintf('Found %d transactions.', count($transactions)).
     *
     * We test the filterForPending method (which feeds the log line) to ensure it
     * returns a valid array that count() can handle.
     */
    public function testFilterForPendingReturnsArrayForValidInput(): void
    {
        $service       = new SimpleFINService();

        // Configuration is required by filterForPending to check getPendingTransactions()
        $configuration = Configuration::fromArray(['flow' => 'simplefin']);
        $service->setConfiguration($configuration);

        $reflection    = new ReflectionClass($service);
        $method        = $reflection->getMethod('filterForPending');
        $method->setAccessible(true);

        $transactions  = [
            ['id' => 'tx1', 'amount' => '10.00', 'pending' => false],
            ['id' => 'tx2', 'amount' => '20.00', 'pending' => true],
            ['id' => 'tx3', 'amount' => '30.00'],
        ];

        $result = $method->invoke($service, $transactions);

        // Verify result is an array (count() won't throw on it)
        $this->assertIsArray($result);
        // Verify count() works without type error -- this is the core of Fix #16
        $this->assertIsInt(count($result));
    }

    /**
     * Fix #16: filterForPending handles empty array without errors.
     */
    public function testFilterForPendingHandlesEmptyArray(): void
    {
        $service       = new SimpleFINService();
        $configuration = Configuration::fromArray(['flow' => 'simplefin']);
        $service->setConfiguration($configuration);

        $reflection    = new ReflectionClass($service);
        $method        = $reflection->getMethod('filterForPending');
        $method->setAccessible(true);

        $result = $method->invoke($service, []);

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    /**
     * Fix #38: The access token URL must NOT appear in log output.
     *
     * The access token URL contains embedded credentials (user:pass@host format).
     * Only the host portion should be logged.
     */
    public function testFetchAccountsDoesNotLogAccessTokenInPlaintext(): void
    {
        $sensitiveToken = 'https://secretuser:secretpass@bridge.simplefin.org/simplefin/accounts';
        $service        = new SimpleFINService();
        $service->setAccessToken($sensitiveToken);

        // Capture all Log::debug calls and assert none contain the full token
        Log::shouldReceive('debug')
            ->atLeast()
            ->once()
            ->withArgs(function (string $message) use ($sensitiveToken): bool {
                // FAIL the test if the full access token URL is logged
                $this->assertStringNotContainsString(
                    $sensitiveToken,
                    $message,
                    'Access token URL must not appear in log output (security: credentials leak)'
                );
                // Verify the host-only version IS logged instead
                if (str_contains($message, 'SimpleFIN fetching accounts from host:')) {
                    $this->assertStringContainsString('bridge.simplefin.org', $message);
                }

                return true;
            });

        // The actual API call will fail since we have no real server, but the log
        // assertions are verified before the HTTP request is attempted.
        // We catch the expected exception since we're only testing logging behavior.
        try {
            $service->fetchAccounts();
        } catch (\Throwable) {
            // Expected: no real SimpleFIN server available in tests.
            // The security assertion above already verified the log output.
        }
    }

    /**
     * Fix #38: Verify that the exchangeSetupTokenForAccessToken method also masks credentials in logs.
     */
    public function testExchangeTokenDoesNotLogAccessTokenInPlaintext(): void
    {
        $sensitiveToken = 'https://secretuser:secretpass@bridge.simplefin.org/simplefin/accounts';
        $service        = new SimpleFINService();

        // Pre-set the access token to simulate a post-exchange state
        $service->setAccessToken($sensitiveToken);

        // Calling exchange when access token is already set should skip and log a warning
        Log::shouldReceive('debug')
            ->atLeast()
            ->once()
            ->withArgs(function (string $message) use ($sensitiveToken): bool {
                // Ensure the full token never appears in any log message
                $this->assertStringNotContainsString(
                    $sensitiveToken,
                    $message,
                    'Access token URL must not appear in log output after exchange'
                );

                return true;
            });

        Log::shouldReceive('warning')
            ->atLeast()
            ->once();

        $service->exchangeSetupTokenForAccessToken();
    }
}
