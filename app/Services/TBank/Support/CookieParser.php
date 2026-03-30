<?php

declare(strict_types=1);

namespace App\Services\TBank\Support;

/**
 * Shared cookie-header parsing utilities for TBank.
 *
 * Extracted from OAuthClient and SecretManager to eliminate duplicated
 * cookiePairsFromCookieHeader(), extractSessionIdFromCookieHeader(),
 * normalizeCookieHeader(), and mergeCookieHeaders() methods.
 */
class CookieParser
{
    /**
     * Parse a Cookie header string into an associative array of name => value pairs.
     *
     * @return array<string, string>
     */
    public static function cookiePairsFromCookieHeader(string $cookieHeader): array
    {
        $result = [];
        foreach (explode(';', trim($cookieHeader)) as $chunk) {
            $part = trim($chunk);
            if ('' === $part || !str_contains($part, '=')) {
                continue;
            }
            [$name, $value] = explode('=', $part, 2);
            $name  = trim($name);
            $value = trim($value);
            if ('' === $name || '' === $value) {
                continue;
            }
            $result[$name] = $value;
        }

        return $result;
    }

    /**
     * Extract the TBank session ID from a Cookie header string.
     *
     * Looks for known session cookie names in priority order: psid, old_session_id, sessionid.
     */
    public static function extractSessionIdFromCookieHeader(string $cookieHeader): string
    {
        $pairs = self::cookiePairsFromCookieHeader($cookieHeader);
        foreach (['psid', 'old_session_id', 'sessionid'] as $key) {
            $value = trim((string)($pairs[$key] ?? ''));
            if ('' !== $value) {
                return $value;
            }
        }

        return '';
    }

    /**
     * Normalize a Cookie header string: deduplicate, trim, and rebuild.
     */
    public static function normalizeCookieHeader(string $cookieHeader): string
    {
        $input = trim($cookieHeader);
        if ('' === $input) {
            return '';
        }
        $parts  = array_filter(array_map('trim', explode(';', $input)));
        $result = [];
        foreach ($parts as $part) {
            if (!str_contains($part, '=')) {
                continue;
            }
            [$name, $value] = explode('=', $part, 2);
            $name  = trim($name);
            $value = trim($value);
            if ('' === $name || '' === $value) {
                continue;
            }
            $result[$name] = $value;
        }

        return self::buildCookieHeaderString($result);
    }

    /**
     * Merge an existing Cookie header with incoming Set-Cookie headers.
     *
     * Empty values in Set-Cookie headers cause the cookie to be removed.
     *
     * @param string $existingCookieHeader The current Cookie header string
     * @param array  $setCookieHeaders     Array of Set-Cookie header values
     */
    public static function mergeCookieHeaders(string $existingCookieHeader, array $setCookieHeaders): string
    {
        $cookies = self::cookiePairsFromCookieHeader($existingCookieHeader);
        foreach ($setCookieHeaders as $setCookieHeader) {
            $pair = trim((string)$setCookieHeader);
            if ('' === $pair) {
                continue;
            }
            [$firstPart] = explode(';', $pair, 2);
            if (!str_contains($firstPart, '=')) {
                continue;
            }
            [$name, $value] = explode('=', $firstPart, 2);
            $name  = trim($name);
            $value = trim($value);
            if ('' === $name) {
                continue;
            }
            if ('' === $value) {
                unset($cookies[$name]);

                continue;
            }
            $cookies[$name] = $value;
        }

        return self::buildCookieHeaderString($cookies);
    }

    /**
     * Merge two Cookie header strings together.
     *
     * Incoming cookies override existing ones with the same name.
     */
    public static function mergeCookieHeaderStrings(string $existingCookieHeader, string $incomingCookieHeader): string
    {
        $cookies = self::cookiePairsFromCookieHeader($existingCookieHeader);
        foreach (self::cookiePairsFromCookieHeader($incomingCookieHeader) as $name => $value) {
            $cookies[$name] = $value;
        }

        return self::buildCookieHeaderString($cookies);
    }

    /**
     * Build a Cookie header string from an associative array.
     *
     * @param array<string, string> $cookies
     */
    private static function buildCookieHeaderString(array $cookies): string
    {
        return implode('; ', array_map(
            static fn(string $name, string $value): string => sprintf('%s=%s', $name, $value),
            array_keys($cookies),
            $cookies
        ));
    }
}
