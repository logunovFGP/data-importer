<?php

declare(strict_types=1);

namespace Tests\Unit\Services\TRC20;

use Tests\TestCase;

/**
 * Tests for Fix #5: TRC20 pagination cursor shared across wallets.
 *
 * Verifies that the lastCursor is reset to null at the start of each wallet iteration
 * so that wallet B does not inherit wallet A's TronGrid fingerprint.
 *
 * Since the get() method makes HTTP calls, we test the cursor reset indirectly by
 * verifying that buildQuery receives null fingerprint at the start of each wallet.
 * We use reflection to inspect the relevant code path.
 *
 * @internal
 * @coversNothing
 */
final class CursorPerWalletTest extends TestCase
{
    /**
     * Verify that buildQuery called with null fingerprint does not include a fingerprint key.
     */
    public function testBuildQueryWithNullFingerprintOmitsFingerprint(): void
    {
        $request = new \App\Services\TRC20\Request\GetTransactionsRequest(
            'test-api-key',
            ['TTEST0000000000000000000000000000001'],
            200
        );

        $reflection = new \ReflectionMethod($request, 'buildQuery');
        $reflection->setAccessible(true);

        $query = $reflection->invoke($request, '2025-01-01', '2025-12-31', null);

        $this->assertArrayNotHasKey('fingerprint', $query);
    }

    /**
     * Verify that buildQuery with a non-null fingerprint includes it.
     */
    public function testBuildQueryWithFingerprintIncludesIt(): void
    {
        $request = new \App\Services\TRC20\Request\GetTransactionsRequest(
            'test-api-key',
            ['TTEST0000000000000000000000000000001'],
            200
        );

        $reflection = new \ReflectionMethod($request, 'buildQuery');
        $reflection->setAccessible(true);

        $query = $reflection->invoke($request, '2025-01-01', '2025-12-31', 'abc123fingerprint');

        $this->assertArrayHasKey('fingerprint', $query);
        $this->assertSame('abc123fingerprint', $query['fingerprint']);
    }

    /**
     * Verify that the source code properly resets lastCursor to null inside the wallet loop.
     *
     * This is a structural test: we read the source and confirm the reset is present.
     * It guards against regressions where the reset line could be accidentally removed.
     */
    public function testSourceCodeResetsCursorInsideWalletLoop(): void
    {
        $sourceFile = __DIR__ . '/../../../../app/Services/TRC20/Request/GetTransactionsRequest.php';
        $this->assertFileExists($sourceFile);

        $source = file_get_contents($sourceFile);

        // The foreach loop over wallets must contain a cursor reset before buildQuery is called.
        // We check that between "foreach ($wallets" and "buildQuery", there's a "$lastCursor = null".
        $pattern = '/foreach\s*\(\$wallets\s+as\s+\$wallet\)\s*\{[^}]*?\$lastCursor\s*=\s*null\s*;[^}]*?buildQuery/s';
        $this->assertMatchesRegularExpression(
            $pattern,
            $source,
            'Expected $lastCursor = null before buildQuery inside the foreach ($wallets) loop'
        );
    }
}
