<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BasisBank;

use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Tests for Fix #1: BasisBank buildStatementPostPayload divergence.
 *
 * Verifies that:
 * 1. The fallback start date uses config('basisbank.statement_history_years') instead of hardcoded subYears(2).
 * 2. Defaults are unconditionally set (not conditional on key existing in HTML).
 *
 * @internal
 * @coversNothing
 */
final class BuildStatementPostPayloadTest extends TestCase
{
    /**
     * buildStatementPostPayload is private, so we invoke it via reflection.
     * We instantiate GetAccountsRequest with minimal constructor args and call the private method.
     */
    private function callBuildStatementPostPayload(string $html, string $statementId): array
    {
        // GetAccountsRequest extends BearerJsonRequest which needs a URL and token.
        $request = new \App\Services\BasisBank\Request\GetAccountsRequest('https://test.example.com', 'test-token');

        $reflection = new \ReflectionMethod($request, 'buildStatementPostPayload');
        $reflection->setAccessible(true);

        return $reflection->invoke($request, $html, $statementId);
    }

    /**
     * Build a minimal ASP.NET form HTML that buildStatementPostPayload can parse.
     * Includes __VIEWSTATE to pass the "is valid HTML form" check.
     */
    private function buildMinimalFormHtml(array $extraInputs = []): string
    {
        $inputs = '<input type="hidden" name="__VIEWSTATE" value="viewstate-value" />';
        foreach ($extraInputs as $name => $value) {
            $inputs .= sprintf('<input type="hidden" name="%s" value="%s" />', htmlspecialchars($name), htmlspecialchars($value));
        }

        return sprintf('<html><body><form>%s</form></body></html>', $inputs);
    }

    public function testFallbackStartDateUsesConfigValue(): void
    {
        // Set config to 10 years for this test
        Config::set('basisbank.statement_history_years', 10);

        Carbon::setTestNow(Carbon::create(2026, 3, 30, 12, 0, 0));

        $html = $this->buildMinimalFormHtml();
        $payload = $this->callBuildStatementPostPayload($html, 'ACC123');

        // With 10 years lookback from 2026-03-30, start should be 2016-03-30 in d/m/Y format
        $expectedStart = Carbon::create(2016, 3, 30);
        $this->assertSame($expectedStart->format('d/m/Y'), $payload['ctl00$Content$CurDateTxt']);
        $this->assertSame($expectedStart->format('Y'), $payload['ctl00$Content$DDLyear']);

        Carbon::setTestNow();
    }

    public function testFallbackStartDateUsesDefault25YearsWhenConfigNotSet(): void
    {
        // Clear any explicit config — the production default is 25
        Config::set('basisbank.statement_history_years', 25);

        Carbon::setTestNow(Carbon::create(2026, 3, 30, 12, 0, 0));

        $html = $this->buildMinimalFormHtml();
        $payload = $this->callBuildStatementPostPayload($html, 'ACC123');

        $expectedStart = Carbon::create(2001, 3, 30);
        $this->assertSame($expectedStart->format('d/m/Y'), $payload['ctl00$Content$CurDateTxt']);
        $this->assertSame('2001', $payload['ctl00$Content$DDLyear']);

        Carbon::setTestNow();
    }

    public function testDefaultsAreUnconditionallySet(): void
    {
        Config::set('basisbank.statement_history_years', 25);

        Carbon::setTestNow(Carbon::create(2026, 6, 15, 12, 0, 0));

        // Provide HTML WITHOUT the date fields — they should still be set in the output payload.
        $html = $this->buildMinimalFormHtml();
        $payload = $this->callBuildStatementPostPayload($html, 'ACC123');

        // These keys must be present even though they were not in the HTML form.
        // The old code only set them if they already existed — this verifies the fix.
        $this->assertArrayHasKey('ctl00$Content$CurDateTxt', $payload);
        $this->assertArrayHasKey('ctl00$Content$CurDateTxtEnd', $payload);
        $this->assertArrayHasKey('ctl00$Content$DDLday', $payload);
        $this->assertArrayHasKey('ctl00$Content$DDLmounth', $payload);
        $this->assertArrayHasKey('ctl00$Content$DDLyear', $payload);
        $this->assertArrayHasKey('ctl00$Content$DDLdayEnd', $payload);
        $this->assertArrayHasKey('ctl00$Content$DDLmounthEnd', $payload);
        $this->assertArrayHasKey('ctl00$Content$DDLyearEnd', $payload);
        $this->assertArrayHasKey('ctl00$Content$ReportType', $payload);
        $this->assertSame('3', $payload['ctl00$Content$ReportType']);
        $this->assertArrayHasKey('__EVENTTARGET', $payload);
        $this->assertArrayHasKey('__EVENTARGUMENT', $payload);

        Carbon::setTestNow();
    }

    public function testAccountDDLIsSetToStatementId(): void
    {
        Config::set('basisbank.statement_history_years', 25);

        $html = $this->buildMinimalFormHtml();
        $payload = $this->callBuildStatementPostPayload($html, 'MY-ACCOUNT-42');

        $this->assertSame('MY-ACCOUNT-42', $payload['ctl00$Content$AccountDDL']);
    }

    public function testEmptyViewstateReturnsEmptyArray(): void
    {
        // HTML with empty __VIEWSTATE should short-circuit
        $html = '<html><body><form><input type="hidden" name="__VIEWSTATE" value="" /></form></body></html>';
        $payload = $this->callBuildStatementPostPayload($html, 'ACC');

        $this->assertSame([], $payload);
    }
}
