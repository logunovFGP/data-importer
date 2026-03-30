<?php

declare(strict_types=1);

namespace Tests\Unit\Services\TBank\Support;

use App\Services\TBank\Support\CookieParser;
use Tests\TestCase;

/**
 * Fix #23: Verify CookieParser methods extracted from OAuthClient and SecretManager.
 *
 * @internal
 *
 * @coversDefaultClass \App\Services\TBank\Support\CookieParser
 */
final class CookieParserTest extends TestCase
{
    // --- cookiePairsFromCookieHeader ---

    /**
     * @covers ::cookiePairsFromCookieHeader
     */
    public function testParsesStandardCookieHeader(): void
    {
        $header = 'psid=abc123; theme=dark; lang=en';
        $pairs  = CookieParser::cookiePairsFromCookieHeader($header);

        $this->assertSame(['psid' => 'abc123', 'theme' => 'dark', 'lang' => 'en'], $pairs);
    }

    /**
     * @covers ::cookiePairsFromCookieHeader
     */
    public function testEmptyCookieHeaderReturnsEmptyArray(): void
    {
        $this->assertSame([], CookieParser::cookiePairsFromCookieHeader(''));
        $this->assertSame([], CookieParser::cookiePairsFromCookieHeader('   '));
    }

    /**
     * @covers ::cookiePairsFromCookieHeader
     */
    public function testSkipsEntriesWithoutEquals(): void
    {
        $header = 'psid=abc123; invalid_chunk; lang=en';
        $pairs  = CookieParser::cookiePairsFromCookieHeader($header);

        $this->assertSame(['psid' => 'abc123', 'lang' => 'en'], $pairs);
    }

    /**
     * @covers ::cookiePairsFromCookieHeader
     */
    public function testSkipsEntriesWithEmptyNameOrValue(): void
    {
        $header = '=noname; novalue=; valid=yes';
        $pairs  = CookieParser::cookiePairsFromCookieHeader($header);

        $this->assertSame(['valid' => 'yes'], $pairs);
    }

    // --- extractSessionIdFromCookieHeader ---

    /**
     * @covers ::extractSessionIdFromCookieHeader
     */
    public function testExtractsPsidAsSessionId(): void
    {
        $header = 'theme=dark; psid=session-id-value; lang=en';
        $this->assertSame('session-id-value', CookieParser::extractSessionIdFromCookieHeader($header));
    }

    /**
     * @covers ::extractSessionIdFromCookieHeader
     */
    public function testExtractsOldSessionIdWhenNoPsid(): void
    {
        $header = 'theme=dark; old_session_id=old-sess; lang=en';
        $this->assertSame('old-sess', CookieParser::extractSessionIdFromCookieHeader($header));
    }

    /**
     * @covers ::extractSessionIdFromCookieHeader
     */
    public function testExtractsSessionidWhenNoPsidOrOldSessionId(): void
    {
        $header = 'theme=dark; sessionid=fallback-sess; lang=en';
        $this->assertSame('fallback-sess', CookieParser::extractSessionIdFromCookieHeader($header));
    }

    /**
     * @covers ::extractSessionIdFromCookieHeader
     */
    public function testReturnsEmptyWhenNoSessionCookieFound(): void
    {
        $header = 'theme=dark; lang=en';
        $this->assertSame('', CookieParser::extractSessionIdFromCookieHeader($header));
    }

    /**
     * @covers ::extractSessionIdFromCookieHeader
     */
    public function testPsidHasPriorityOverOldSessionId(): void
    {
        $header = 'psid=primary; old_session_id=secondary; sessionid=tertiary';
        $this->assertSame('primary', CookieParser::extractSessionIdFromCookieHeader($header));
    }

    // --- normalizeCookieHeader ---

    /**
     * @covers ::normalizeCookieHeader
     */
    public function testNormalizesExtraWhitespace(): void
    {
        $header = '  psid = abc ;  lang =  en  ;  ';
        $result = CookieParser::normalizeCookieHeader($header);

        $this->assertSame('psid=abc; lang=en', $result);
    }

    /**
     * @covers ::normalizeCookieHeader
     */
    public function testNormalizeEmptyStringReturnsEmpty(): void
    {
        $this->assertSame('', CookieParser::normalizeCookieHeader(''));
        $this->assertSame('', CookieParser::normalizeCookieHeader('   '));
    }

    /**
     * @covers ::normalizeCookieHeader
     */
    public function testNormalizeDeduplicatesCookieNames(): void
    {
        $header = 'psid=first; lang=en; psid=second';
        $result = CookieParser::normalizeCookieHeader($header);

        $this->assertSame('psid=second; lang=en', $result);
    }

    // --- mergeCookieHeaders ---

    /**
     * @covers ::mergeCookieHeaders
     */
    public function testMergesSetCookieIntoExisting(): void
    {
        $existing   = 'psid=old; lang=en';
        $setCookies = ['psid=new; Path=/; HttpOnly', 'theme=dark; Path=/'];

        $result = CookieParser::mergeCookieHeaders($existing, $setCookies);

        $this->assertSame('psid=new; lang=en; theme=dark', $result);
    }

    /**
     * @covers ::mergeCookieHeaders
     */
    public function testMergeRemovesCookieWithEmptyValue(): void
    {
        $existing   = 'psid=abc; lang=en';
        $setCookies = ['psid=; Path=/'];

        $result = CookieParser::mergeCookieHeaders($existing, $setCookies);

        $this->assertSame('lang=en', $result);
    }

    // --- mergeCookieHeaderStrings ---

    /**
     * @covers ::mergeCookieHeaderStrings
     */
    public function testMergesTwoCookieHeaderStrings(): void
    {
        $existing = 'psid=old; lang=en';
        $incoming = 'psid=new; theme=dark';

        $result = CookieParser::mergeCookieHeaderStrings($existing, $incoming);

        $this->assertSame('psid=new; lang=en; theme=dark', $result);
    }

    /**
     * @covers ::mergeCookieHeaderStrings
     */
    public function testMergeWithEmptyIncomingReturnsExisting(): void
    {
        $existing = 'psid=abc; lang=en';

        $result = CookieParser::mergeCookieHeaderStrings($existing, '');

        $this->assertSame('psid=abc; lang=en', $result);
    }
}
