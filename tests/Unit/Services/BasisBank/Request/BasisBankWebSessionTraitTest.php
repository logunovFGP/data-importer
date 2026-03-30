<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BasisBank\Request;

use App\Services\BasisBank\Request\BasisBankWebSessionTrait;
use PHPUnit\Framework\TestCase;

/**
 * Concrete class that uses the trait so we can test its methods directly.
 */
class BasisBankWebSessionTraitTestHelper
{
    use BasisBankWebSessionTrait;

    private const string BASE_WEB_URL = 'https://www.bankonline.ge';
    private const string CARD_MODULE_PATH = '/Handlers/CardModule.ashx';
    private const string STATEMENT_PAGE_PATH = '/Accounts/Statement/Statement.aspx';
    private const int MAX_SESSION_RECOVERY_ATTEMPTS = 1;
    private const string DATE_FORMAT = 'd/m/Y';

    private float $timeOut = 3.14;
    private array $sessionCookies = [];
    private bool $sessionCookiesLoaded = false;
    private readonly string $sessionArtifact;

    public function __construct(string $sessionArtifact = '')
    {
        $this->sessionArtifact = $sessionArtifact;
    }

    // Expose private trait methods for testing via public wrappers.

    public function testDecodeArtifact(string $artifact): array
    {
        return $this->decodeArtifact($artifact);
    }

    public function testEncodeArtifact(array $cookies): string
    {
        return $this->encodeArtifact($cookies);
    }

    public function testParseAmountValue(mixed $value): float
    {
        return $this->parseAmountValue($value);
    }

    public function testNormalizeWhitespace(string $value): string
    {
        return $this->normalizeWhitespace($value);
    }

    public function testContainsLoginForm(string $html): bool
    {
        return $this->containsLoginForm($html);
    }

    public function testIsDeadSessionPayload(mixed $payload): bool
    {
        return $this->isDeadSessionPayload($payload);
    }

    public function testCoercePayload(mixed $payload): mixed
    {
        return $this->coercePayload($payload);
    }

    public function testBuildCookieHeader(array $cookies): string
    {
        return $this->buildCookieHeader($cookies);
    }

    public function testNormalizeDate(string $value): string
    {
        return $this->normalizeDate($value);
    }

    public function testExtractStatementTransactionId(array $texts): string
    {
        return $this->extractStatementTransactionId($texts);
    }

    public function testExtractArrayPayload(mixed $payload): array
    {
        return $this->extractArrayPayload($payload);
    }
}

class BasisBankWebSessionTraitTest extends TestCase
{
    private BasisBankWebSessionTraitTestHelper $helper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->helper = new BasisBankWebSessionTraitTestHelper();
    }

    // ------------------------------------------------------------------
    // decodeArtifact / encodeArtifact round-trip
    // ------------------------------------------------------------------

    public function testDecodeEncodeRoundTrip(): void
    {
        $cookies = ['ASP.NET_SessionId' => 'abc123', 'auth_token' => 'xyz789'];
        $encoded = $this->helper->testEncodeArtifact($cookies);
        $decoded = $this->helper->testDecodeArtifact($encoded);

        $this->assertSame($cookies, $decoded);
    }

    public function testDecodeArtifactFromNameValueList(): void
    {
        $list = [
            ['name' => 'session', 'value' => 'abc'],
            ['name' => 'token', 'value' => 'xyz'],
        ];
        $encoded = base64_encode((string)json_encode($list));
        $decoded = $this->helper->testDecodeArtifact($encoded);

        $this->assertSame(['session' => 'abc', 'token' => 'xyz'], $decoded);
    }

    public function testDecodeArtifactFromKeyValueMap(): void
    {
        $map = ['cookie1' => 'value1', 'cookie2' => 'value2'];
        $encoded = base64_encode((string)json_encode($map));
        $decoded = $this->helper->testDecodeArtifact($encoded);

        $this->assertSame($map, $decoded);
    }

    public function testDecodeArtifactEmptyString(): void
    {
        $this->assertSame([], $this->helper->testDecodeArtifact(''));
    }

    public function testDecodeArtifactInvalidBase64(): void
    {
        // Invalid base64 that isn't valid JSON either
        $this->assertSame([], $this->helper->testDecodeArtifact('not-valid-base64!!!'));
    }

    // ------------------------------------------------------------------
    // parseAmountValue
    // ------------------------------------------------------------------

    public function testParseAmountValueSimpleFloat(): void
    {
        $this->assertEqualsWithDelta(123.45, $this->helper->testParseAmountValue('123.45'), 0.001);
    }

    public function testParseAmountValueNumericInput(): void
    {
        $this->assertEqualsWithDelta(42.0, $this->helper->testParseAmountValue(42), 0.001);
    }

    public function testParseAmountValueWithCommaDecimal(): void
    {
        // European format: comma as decimal separator
        $this->assertEqualsWithDelta(1234.56, $this->helper->testParseAmountValue('1234,56'), 0.001);
    }

    public function testParseAmountValueWithDotThousandsSeparator(): void
    {
        // European format: dot as thousands, comma as decimal
        $this->assertEqualsWithDelta(1234.56, $this->helper->testParseAmountValue('1.234,56'), 0.001);
    }

    public function testParseAmountValueNegativeInParentheses(): void
    {
        $this->assertEqualsWithDelta(-500.0, $this->helper->testParseAmountValue('(500.00)'), 0.001);
    }

    public function testParseAmountValueWithNonBreakingSpaces(): void
    {
        $this->assertEqualsWithDelta(1234.56, $this->helper->testParseAmountValue("1\u{00A0}234.56"), 0.001);
    }

    public function testParseAmountValueEmptyString(): void
    {
        $this->assertEqualsWithDelta(0.0, $this->helper->testParseAmountValue(''), 0.001);
    }

    public function testParseAmountValueNull(): void
    {
        $this->assertEqualsWithDelta(0.0, $this->helper->testParseAmountValue(null), 0.001);
    }

    public function testParseAmountValueNegativeSign(): void
    {
        $this->assertEqualsWithDelta(-100.50, $this->helper->testParseAmountValue('-100.50'), 0.001);
    }

    // ------------------------------------------------------------------
    // normalizeWhitespace
    // ------------------------------------------------------------------

    public function testNormalizeWhitespaceCollapsesMultipleSpaces(): void
    {
        $this->assertSame('hello world', $this->helper->testNormalizeWhitespace('hello   world'));
    }

    public function testNormalizeWhitespaceTrimsAndCollapsesNewlines(): void
    {
        $this->assertSame('a b c', $this->helper->testNormalizeWhitespace("  a\n  b\t  c  "));
    }

    public function testNormalizeWhitespaceEmptyString(): void
    {
        $this->assertSame('', $this->helper->testNormalizeWhitespace(''));
    }

    public function testNormalizeWhitespaceOnlySpaces(): void
    {
        $this->assertSame('', $this->helper->testNormalizeWhitespace('   '));
    }

    // ------------------------------------------------------------------
    // containsLoginForm
    // ------------------------------------------------------------------

    public function testContainsLoginFormTrue(): void
    {
        $html = '<html><body><input id="UTXT" /><input id="PTXT" /></body></html>';
        $this->assertTrue($this->helper->testContainsLoginForm($html));
    }

    public function testContainsLoginFormFalseOnlyUsername(): void
    {
        $html = '<html><body><input id="UTXT" /></body></html>';
        $this->assertFalse($this->helper->testContainsLoginForm($html));
    }

    public function testContainsLoginFormFalseNoForm(): void
    {
        $html = '<html><body><h1>Balance</h1></body></html>';
        $this->assertFalse($this->helper->testContainsLoginForm($html));
    }

    public function testContainsLoginFormFalseEmpty(): void
    {
        $this->assertFalse($this->helper->testContainsLoginForm(''));
    }

    // ------------------------------------------------------------------
    // isDeadSessionPayload
    // ------------------------------------------------------------------

    public function testIsDeadSessionPayloadStringTrue(): void
    {
        $this->assertTrue($this->helper->testIsDeadSessionPayload('{"Status":"DeadSession"}'));
    }

    public function testIsDeadSessionPayloadArrayTrue(): void
    {
        $this->assertTrue($this->helper->testIsDeadSessionPayload(['Status' => 'DeadSession']));
    }

    public function testIsDeadSessionPayloadLowercaseKey(): void
    {
        $this->assertTrue($this->helper->testIsDeadSessionPayload(['status' => 'DeadSession']));
    }

    public function testIsDeadSessionPayloadFalse(): void
    {
        $this->assertFalse($this->helper->testIsDeadSessionPayload('{"Status":"OK"}'));
    }

    // ------------------------------------------------------------------
    // coercePayload
    // ------------------------------------------------------------------

    public function testCoercePayloadParsesJson(): void
    {
        $this->assertSame(['key' => 'value'], $this->helper->testCoercePayload('{"key":"value"}'));
    }

    public function testCoercePayloadDoubleEncodedJson(): void
    {
        // The current coercePayload only decodes strings starting with '{' or '['.
        // A double-encoded JSON string starts with '"', so the outer layer is NOT decoded.
        $inner = json_encode(['a' => 1]);
        $outer = json_encode($inner);
        $result = $this->helper->testCoercePayload($outer);
        // Returns the raw string since it starts with '"', not '{' or '['
        $this->assertSame($outer, $result);
    }

    public function testCoercePayloadNonJsonString(): void
    {
        $this->assertSame('hello', $this->helper->testCoercePayload('hello'));
    }

    public function testCoercePayloadAlreadyArray(): void
    {
        $data = ['x' => 'y'];
        $this->assertSame($data, $this->helper->testCoercePayload($data));
    }

    // ------------------------------------------------------------------
    // buildCookieHeader
    // ------------------------------------------------------------------

    public function testBuildCookieHeader(): void
    {
        $cookies = ['session' => 'abc', 'token' => 'xyz'];
        $this->assertSame('session=abc; token=xyz', $this->helper->testBuildCookieHeader($cookies));
    }

    public function testBuildCookieHeaderSkipsEmptyNames(): void
    {
        $cookies = ['' => 'empty', 'valid' => 'yes'];
        $this->assertSame('valid=yes', $this->helper->testBuildCookieHeader($cookies));
    }

    // ------------------------------------------------------------------
    // normalizeDate
    // ------------------------------------------------------------------

    public function testNormalizeDateIsoFormat(): void
    {
        $this->assertSame('2025-03-15', $this->helper->testNormalizeDate('2025-03-15'));
    }

    public function testNormalizeDateSlashFormat(): void
    {
        // Carbon::parse treats '/' as M/D/Y (US convention), so 01/12/2025 = Jan 12, 2025.
        $this->assertSame('2025-01-12', $this->helper->testNormalizeDate('01/12/2025'));
    }

    public function testNormalizeDateThrowsOnUnparseableValue(): void
    {
        $this->expectException(\App\Exceptions\ImporterErrorException::class);
        $this->expectExceptionMessage('Cannot parse date: not-a-date-at-all');
        $this->helper->testNormalizeDate('not-a-date-at-all');
    }

    // ------------------------------------------------------------------
    // extractStatementTransactionId
    // ------------------------------------------------------------------

    public function testExtractStatementTransactionIdRT(): void
    {
        $this->assertSame('RT123456', $this->helper->testExtractStatementTransactionId(['some text RT123456 more']));
    }

    public function testExtractStatementTransactionIdLongNumber(): void
    {
        // The regex matches 'REF[- ]?\d{5,}' before bare '\d{10,}', and strtoupper is applied.
        // 'ref 1234567890' matches as 'REF 1234567890' (with the space included in the capture).
        $this->assertSame('REF 1234567890', $this->helper->testExtractStatementTransactionId(['ref 1234567890 done']));
    }

    public function testExtractStatementTransactionIdEmpty(): void
    {
        $this->assertSame('', $this->helper->testExtractStatementTransactionId(['no transaction id here']));
    }

    // ------------------------------------------------------------------
    // extractArrayPayload (formerly extractRowsFromTransactionPayload)
    // ------------------------------------------------------------------

    public function testExtractArrayPayloadFlatList(): void
    {
        $payload = [['id' => 1], ['id' => 2]];
        $this->assertSame($payload, $this->helper->testExtractArrayPayload($payload));
    }

    public function testExtractArrayPayloadNestedD(): void
    {
        $payload = ['d' => [['id' => 1], ['id' => 2]]];
        $this->assertSame([['id' => 1], ['id' => 2]], $this->helper->testExtractArrayPayload($payload));
    }

    public function testExtractArrayPayloadNestedTransactions(): void
    {
        $payload = ['transactions' => [['id' => 1]]];
        $this->assertSame([['id' => 1]], $this->helper->testExtractArrayPayload($payload));
    }

    public function testExtractArrayPayloadEmpty(): void
    {
        $this->assertSame([], $this->helper->testExtractArrayPayload(['unknown' => 'data']));
    }
}
