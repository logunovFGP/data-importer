<?php

declare(strict_types=1);

namespace Tests\Unit\Services\TRC20\Request;

use App\Services\TRC20\Request\GetTransactionsRequest;
use App\Services\TRC20\Request\GetTrxTransactionsRequest;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Fix #26: Verify that buildQuery() and extractFingerprint() are provided by
 * TRC20RequestTrait and produce consistent results in both transaction classes.
 *
 * @internal
 */
final class TRC20QueryBuildTest extends TestCase
{
    /**
     * Both classes should produce identical query arrays for the same inputs.
     */
    public function testBuildQueryIdenticalAcrossClasses(): void
    {
        $trc20   = new GetTransactionsRequest('key', ['TWallet'], 50);
        $trx     = new GetTrxTransactionsRequest('key', ['TWallet'], 50);
        $method1 = new ReflectionMethod(GetTransactionsRequest::class, 'buildQuery');
        $method2 = new ReflectionMethod(GetTrxTransactionsRequest::class, 'buildQuery');

        $query1 = $method1->invoke($trc20, '2025-01-01', '2025-01-31', null);
        $query2 = $method2->invoke($trx, '2025-01-01', '2025-01-31', null);

        $this->assertSame($query1, $query2, 'buildQuery() must produce identical results from both classes');
    }

    /**
     * buildQuery includes end-of-day (23:59:59) for dateTo.
     */
    public function testBuildQueryDateToIncludesEndOfDay(): void
    {
        $instance = new GetTransactionsRequest('key', ['TWallet'], 100);
        $method   = new ReflectionMethod(GetTransactionsRequest::class, 'buildQuery');

        $query = $method->invoke($instance, null, '2025-10-16', null);

        // '2025-10-16 23:59:59' → end of day included
        $expectedMax = strtotime('2025-10-16 23:59:59') * 1000;
        $this->assertSame($expectedMax, $query['max_timestamp']);
    }

    /**
     * buildQuery without dates should not include timestamp fields.
     */
    public function testBuildQueryNoDatesNoTimestamps(): void
    {
        $instance = new GetTransactionsRequest('key', ['TWallet'], 100);
        $method   = new ReflectionMethod(GetTransactionsRequest::class, 'buildQuery');

        $query = $method->invoke($instance, null, null, null);

        $this->assertArrayNotHasKey('min_timestamp', $query);
        $this->assertArrayNotHasKey('max_timestamp', $query);
    }

    /**
     * buildQuery with fingerprint includes it.
     */
    public function testBuildQueryWithFingerprint(): void
    {
        $instance = new GetTransactionsRequest('key', ['TWallet'], 100);
        $method   = new ReflectionMethod(GetTransactionsRequest::class, 'buildQuery');

        $query = $method->invoke($instance, null, null, 'abc-fingerprint-123');

        $this->assertSame('abc-fingerprint-123', $query['fingerprint']);
    }

    /**
     * buildQuery with empty fingerprint does not include it.
     */
    public function testBuildQueryEmptyFingerprintExcluded(): void
    {
        $instance = new GetTransactionsRequest('key', ['TWallet'], 100);
        $method   = new ReflectionMethod(GetTransactionsRequest::class, 'buildQuery');

        $query = $method->invoke($instance, null, null, '');

        $this->assertArrayNotHasKey('fingerprint', $query);
    }

    /**
     * extractFingerprint returns the fingerprint from meta.
     */
    public function testExtractFingerprintFromPayload(): void
    {
        $instance = new GetTransactionsRequest('key', ['TWallet']);
        $method   = new ReflectionMethod(GetTransactionsRequest::class, 'extractFingerprint');

        $payload = ['meta' => ['fingerprint' => 'fp-12345']];
        $result  = $method->invoke($instance, $payload);

        $this->assertSame('fp-12345', $result);
    }

    /**
     * extractFingerprint returns null when no fingerprint present.
     */
    public function testExtractFingerprintReturnsNullWhenMissing(): void
    {
        $instance = new GetTransactionsRequest('key', ['TWallet']);
        $method   = new ReflectionMethod(GetTransactionsRequest::class, 'extractFingerprint');

        $payload = ['data' => []];
        $result  = $method->invoke($instance, $payload);

        $this->assertNull($result);
    }

    /**
     * extractFingerprint returns null for empty fingerprint string.
     */
    public function testExtractFingerprintReturnsNullForEmptyString(): void
    {
        $instance = new GetTransactionsRequest('key', ['TWallet']);
        $method   = new ReflectionMethod(GetTransactionsRequest::class, 'extractFingerprint');

        $payload = ['meta' => ['fingerprint' => '']];
        $result  = $method->invoke($instance, $payload);

        $this->assertNull($result);
    }

    /**
     * Both classes produce identical extractFingerprint results.
     */
    public function testExtractFingerprintIdenticalAcrossClasses(): void
    {
        $trc20   = new GetTransactionsRequest('key', ['TWallet']);
        $trx     = new GetTrxTransactionsRequest('key', ['TWallet']);
        $method1 = new ReflectionMethod(GetTransactionsRequest::class, 'extractFingerprint');
        $method2 = new ReflectionMethod(GetTrxTransactionsRequest::class, 'extractFingerprint');

        $payload = ['meta' => ['fingerprint' => 'shared-fp']];

        $this->assertSame(
            $method1->invoke($trc20, $payload),
            $method2->invoke($trx, $payload),
            'extractFingerprint() must produce identical results from both classes'
        );
    }

    /**
     * buildQuery respects pageSize from constructor.
     */
    public function testBuildQueryUsesPageSize(): void
    {
        $instance = new GetTransactionsRequest('key', ['TWallet'], 42);
        $method   = new ReflectionMethod(GetTransactionsRequest::class, 'buildQuery');

        $query = $method->invoke($instance, null, null, null);

        $this->assertSame(42, $query['limit']);
    }
}
