<?php

declare(strict_types=1);

namespace Tests\Unit\Services\TRC20\Request;

use App\Services\TRC20\Request\GetWalletRequest;
use App\Services\TRC20\Support\TRC20TokenFilter;
use Tests\TestCase;

/**
 * Fix #25: Verify that GetWalletRequest no longer has its own
 * getSupportedTokens() / isTokenSupported() methods and instead
 * delegates to TRC20TokenFilter.
 *
 * @internal
 */
final class GetWalletRequestTokenFilterTest extends TestCase
{
    /**
     * GetWalletRequest must NOT have a private getSupportedTokens() method.
     */
    public function testGetSupportedTokensNotDefinedOnGetWalletRequest(): void
    {
        $reflection = new \ReflectionClass(GetWalletRequest::class);

        // Should not have its own getSupportedTokens
        $methods = array_map(
            static fn(\ReflectionMethod $m): string => $m->getName(),
            $reflection->getMethods()
        );

        // getSupportedTokens should not be declared directly on GetWalletRequest
        $ownMethods = array_map(
            static fn(\ReflectionMethod $m): string => $m->getName(),
            array_filter(
                $reflection->getMethods(),
                static fn(\ReflectionMethod $m): bool => $m->getDeclaringClass()->getName() === GetWalletRequest::class
            )
        );

        $this->assertNotContains('getSupportedTokens', $ownMethods, 'getSupportedTokens() should be removed from GetWalletRequest');
        $this->assertNotContains('isTokenSupported', $ownMethods, 'isTokenSupported() should be removed from GetWalletRequest');
    }

    /**
     * TRC20TokenFilter::getSupportedTokens() must be public.
     */
    public function testTRC20TokenFilterGetSupportedTokensIsPublic(): void
    {
        $method = new \ReflectionMethod(TRC20TokenFilter::class, 'getSupportedTokens');

        $this->assertTrue($method->isPublic(), 'TRC20TokenFilter::getSupportedTokens() must be public');
    }

    /**
     * TRC20TokenFilter::isTokenInSupportedList() must be public.
     */
    public function testTRC20TokenFilterIsTokenInSupportedListIsPublic(): void
    {
        $method = new \ReflectionMethod(TRC20TokenFilter::class, 'isTokenInSupportedList');

        $this->assertTrue($method->isPublic(), 'TRC20TokenFilter::isTokenInSupportedList() must be public');
    }

    /**
     * With default config (no supported_tokens restriction), all tokens are accepted.
     */
    public function testAllTokensAcceptedWhenConfigEmpty(): void
    {
        config(['trc20.supported_tokens' => '']);

        $this->assertTrue(TRC20TokenFilter::isTokenInSupportedList('USDT'));
        $this->assertTrue(TRC20TokenFilter::isTokenInSupportedList('TRX'));
        $this->assertTrue(TRC20TokenFilter::isTokenInSupportedList('RANDOM'));
    }

    /**
     * With wildcard config, all tokens are accepted.
     */
    public function testAllTokensAcceptedWhenConfigWildcard(): void
    {
        config(['trc20.supported_tokens' => '*']);

        $this->assertTrue(TRC20TokenFilter::isTokenInSupportedList('USDT'));
        $this->assertTrue(TRC20TokenFilter::isTokenInSupportedList('TRX'));
    }

    /**
     * With explicit list, only listed tokens are accepted.
     */
    public function testOnlyListedTokensAccepted(): void
    {
        config(['trc20.supported_tokens' => 'USDT,TRX']);

        $this->assertTrue(TRC20TokenFilter::isTokenInSupportedList('USDT'));
        $this->assertTrue(TRC20TokenFilter::isTokenInSupportedList('TRX'));
        $this->assertFalse(TRC20TokenFilter::isTokenInSupportedList('RANDOM'));
    }

    /**
     * Case-insensitive matching.
     */
    public function testCaseInsensitiveMatching(): void
    {
        config(['trc20.supported_tokens' => 'USDT,TRX']);

        $this->assertTrue(TRC20TokenFilter::isTokenInSupportedList('usdt'));
        $this->assertTrue(TRC20TokenFilter::isTokenInSupportedList('trx'));
    }
}
