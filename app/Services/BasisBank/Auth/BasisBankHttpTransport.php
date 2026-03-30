<?php

/*
 * BasisBankHttpTransport.php
 *
 * HTTP transport methods extracted from BasisBankWebAuthClient to keep
 * the auth client under 800 lines (see fix #35).
 */

declare(strict_types=1);

namespace App\Services\BasisBank\Auth;

use App\Exceptions\ImporterErrorException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Support\Facades\Log;

trait BasisBankHttpTransport
{
    // Constants duplicated from BasisBankWebAuthClient so that static analysis
    // can resolve self:: references inside the trait without requiring the
    // consuming class to be visible.  The canonical values live in
    // BasisBankWebAuthClient; if they ever change, update both locations.
    private const string BASE_URL      = 'https://www.bankonline.ge';
    private const string LOGIN_PATH    = '/Login.aspx';
    private const string BALANCE_PATH  = '/Balance.aspx';
    private const string BTOOLKIT_LOGIN_PATH = '/Handlers/BToolkit.ashx?Action=GetSessionId&Type=Login';
    private const string BTOOLKIT_DEVICE_BINDING_PATH = '/Handlers/BToolkit.ashx?Action=GetSessionId&Type=DeviceBinding';
    private const string CARD_MODULE_CHECK_SESSION_PATH = '/Handlers/CardModule.ashx?funq=checksession';
    private const string BROWSER_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36';
    private const float DEFAULT_CONNECT_TIMEOUT = 15.0;
    private const float DEFAULT_REQUEST_TIMEOUT = 45.0;
    private const int DEFAULT_TIMEOUT_RETRIES   = 2;
    private const int DEFAULT_RETRY_DELAY_MS    = 500;

    /**
     * Lightweight session probe via CardModule checksession.
     * Returns true if session appears alive, false if dead.
     * Matches ZenPlugins checkCardSessionAlive() pattern.
     */
    private function probeCheckSession(?string $cookieHeader): bool
    {
        if (null === $cookieHeader || '' === $cookieHeader) {
            return false;
        }
        $headers = $this->ajaxSessionHeaders($cookieHeader, self::BALANCE_PATH);
        try {
            $client   = $this->createClient();
            $response = $client->request(
                'POST',
                self::CARD_MODULE_CHECK_SESSION_PATH,
                [
                    'headers'          => $headers,
                    'allow_redirects'  => false,
                ]
            );
            $body = (string)$response->getBody();
            // checksession returns JSON with resultCode. A dead session returns an error.
            if (str_contains($body, '"resultCode"') && !str_contains(strtolower($body), 'error')) {
                return true;
            }
            // If the response is a redirect to login or an error, session is dead.
            if ($response->getStatusCode() >= 300) {
                return false;
            }

            return true;
        } catch (TransferException) {
            return false;
        }
    }

    private function requestDeviceBindingSessionId(?string $cookieHeader): void
    {
        $headers = $this->ajaxSessionHeaders($cookieHeader, self::BALANCE_PATH);
        try {
            $client = $this->createClient();
            $client->request(
                'POST',
                self::BTOOLKIT_DEVICE_BINDING_PATH,
                [
                    'headers' => $headers,
                    'allow_redirects' => false,
                ]
            );
        } catch (TransferException) {
            // Best-effort request; trusted-device flow can continue even if this pre-call is unavailable.
        }
    }

    private function requestLoginSessionId(?string $cookieHeader): void
    {
        $headers = $this->ajaxSessionHeaders($cookieHeader, self::LOGIN_PATH);
        try {
            $client = $this->createClient();
            $client->request(
                'POST',
                self::BTOOLKIT_LOGIN_PATH,
                [
                    'headers' => $headers,
                    'allow_redirects' => false,
                ]
            );
        } catch (TransferException) {
            // Best-effort request; login can proceed without it.
        }
    }

    private function requestGet(string $path, ?string $cookieHeader): array
    {
        $headers = $this->defaultHeaders();
        if (null !== $cookieHeader) {
            $headers['Cookie'] = $cookieHeader;
        }
        $maxAttempts = $this->getTimeoutRetryAttempts();
        $delayMs     = $this->getTimeoutRetryDelayMs();

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $client   = $this->createClient();
                $response = $client->request(
                    'GET',
                    $path,
                    [
                        'headers' => $headers,
                        'allow_redirects' => true,
                    ]
                );
                return [
                    'status'   => $response->getStatusCode(),
                    'body'     => (string)$response->getBody(),
                    'response' => $response,
                ];
            } catch (TransferException $e) {
                if (!$this->isTimeoutException($e) || $attempt >= $maxAttempts) {
                    throw new ImporterErrorException(sprintf('BasisBank GET %s failed: %s', $path, $e->getMessage()));
                }
                Log::warning(
                    sprintf(
                        'BasisBank GET %s timed out, retrying (%d/%d).',
                        $path,
                        $attempt,
                        $maxAttempts
                    )
                );
                if ($delayMs > 0) {
                    usleep($delayMs * 1000);
                }
            }
        }
        throw new ImporterErrorException(sprintf('BasisBank GET %s failed after retries.', $path));
    }

    /**
     * GET with manual redirect handling to preserve Set-Cookie headers from intermediate 302s.
     * Used for Balance.aspx in trust device flow where cookie loss breaks the session.
     */
    private function requestGetManual(string $path, ?string $cookieHeader): array
    {
        $headers = $this->defaultHeaders();
        if (null !== $cookieHeader) {
            $headers['Cookie'] = $cookieHeader;
        }
        $maxRedirects = 5;
        $accumulatedCookies = [];

        for ($i = 0; $i < $maxRedirects; $i++) {
            try {
                $client   = $this->createClient();
                $response = $client->request(
                    'GET',
                    $path,
                    [
                        'headers'          => $headers,
                        'allow_redirects'  => false,
                    ]
                );
            } catch (TransferException $e) {
                throw new ImporterErrorException(sprintf('BasisBank GET %s failed: %s', $path, $e->getMessage()));
            }

            $status = $response->getStatusCode();

            // Return result array that includes cookies from this response.
            if ($status < 300 || $status >= 400) {
                return [
                    'status'            => $status,
                    'body'              => (string)$response->getBody(),
                    'response'          => $response,
                    'redirect_cookies'  => $accumulatedCookies,
                ];
            }

            // Follow redirect manually, preserving Set-Cookie from each hop.
            $location = $response->getHeaderLine('Location');
            if ('' === $location) {
                return [
                    'status'            => $status,
                    'body'              => (string)$response->getBody(),
                    'response'          => $response,
                    'redirect_cookies'  => $accumulatedCookies,
                ];
            }

            // Update cookies from redirect response.
            $redirectCookies = $this->collectSetCookieHeaders($response);
            if ([] !== $redirectCookies) {
                $accumulatedCookies = $this->mergeCookies($accumulatedCookies, $redirectCookies);
                $existingCookies = null !== $cookieHeader ? $this->parseCookieHeader($cookieHeader) : [];
                $existingCookies = $this->mergeCookies($existingCookies, $redirectCookies);
                $cookieHeader    = $this->buildCookieHeader($existingCookies);
                $headers['Cookie'] = $cookieHeader;
            }

            $path = $this->normalizeRedirectPath($location, $path);
        }

        throw new ImporterErrorException(sprintf('BasisBank GET %s exceeded redirect limit.', $path));
    }

    private function requestPost(string $path, array $form, ?string $cookieHeader, string $refererPath): array
    {
        $headers = $this->defaultHeaders();
        $headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
        $headers['Referer']     = sprintf('%s%s', self::BASE_URL, $refererPath);
        if (null !== $cookieHeader) {
            $headers['Cookie'] = $cookieHeader;
        }

        $maxAttempts = $this->getTimeoutRetryAttempts();
        $delayMs     = $this->getTimeoutRetryDelayMs();

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $client   = $this->createClient();
                $response = $client->request(
                    'POST',
                    $path,
                    [
                        'headers'      => $headers,
                        'form_params'  => $form,
                        'allow_redirects' => false,
                    ]
                );
                return [
                    'status'   => $response->getStatusCode(),
                    'body'     => (string)$response->getBody(),
                    'location' => $response->getHeaderLine('Location'),
                    'response' => $response,
                ];
            } catch (TransferException $e) {
                if (!$this->isTimeoutException($e) || $attempt >= $maxAttempts) {
                    throw new ImporterErrorException(sprintf('BasisBank POST %s failed: %s', $path, $e->getMessage()));
                }
                Log::warning(
                    sprintf(
                        'BasisBank POST %s timed out, retrying (%d/%d).',
                        $path,
                        $attempt,
                        $maxAttempts
                    )
                );
                if ($delayMs > 0) {
                    usleep($delayMs * 1000);
                }
            }
        }
        throw new ImporterErrorException(sprintf('BasisBank POST %s failed after retries.', $path));
    }

    private function defaultHeaders(): array
    {
        return [
            'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'User-Agent' => sprintf('FF3-data-importer/%s (%s)', config('importer.version'), config('importer.line_b')),
        ];
    }

    private function ajaxSessionHeaders(?string $cookieHeader, string $refererPath): array
    {
        $normalizedReferer = str_starts_with($refererPath, '/') ? $refererPath : ('/' . ltrim($refererPath, '/'));
        $headers = [
            'Accept' => '*/*',
            'Accept-Language' => 'en-US,en;q=0.9',
            'Content-Type' => 'text/plain',
            'Origin' => self::BASE_URL,
            'Referer' => sprintf('%s%s', self::BASE_URL, $normalizedReferer),
            'X-Requested-With' => 'XMLHttpRequest',
            'Sec-Fetch-Dest' => 'empty',
            'Sec-Fetch-Mode' => 'cors',
            'Sec-Fetch-Site' => 'same-origin',
            'User-Agent' => self::BROWSER_USER_AGENT,
        ];

        if (null !== $cookieHeader && '' !== $cookieHeader) {
            $headers['Cookie'] = $cookieHeader;
            $dtpc = $this->extractCookieValue($cookieHeader, 'dtPC');
            if (null !== $dtpc) {
                $headers['x-dtpc'] = $dtpc;
            }
        }

        return $headers;
    }

    private function createClient(): Client
    {
        $options = [
            'base_uri'        => self::BASE_URL,
            'connect_timeout' => $this->getConnectTimeout(),
            'timeout'         => $this->getRequestTimeout(),
            // Bank connections must always verify TLS. The importer.connection.verify toggle
            // is for internal Firefly III API connections only — never disable TLS for bankonline.ge.
            'verify'          => true,
        ];
        return new Client($options);
    }

    private function getConnectTimeout(): float
    {
        $timeout = (float) config('basisbank.auth_connect_timeout', self::DEFAULT_CONNECT_TIMEOUT);
        return $timeout > 0 ? $timeout : self::DEFAULT_CONNECT_TIMEOUT;
    }

    private function getRequestTimeout(): float
    {
        $timeout = (float) config('basisbank.auth_request_timeout', self::DEFAULT_REQUEST_TIMEOUT);
        return $timeout > 0 ? $timeout : self::DEFAULT_REQUEST_TIMEOUT;
    }

    private function getTimeoutRetryAttempts(): int
    {
        $retries = (int) config('basisbank.auth_timeout_retries', self::DEFAULT_TIMEOUT_RETRIES);
        $retries = max(0, $retries);
        return 1 + $retries;
    }

    private function getTimeoutRetryDelayMs(): int
    {
        $delay = (int) config('basisbank.auth_retry_delay_ms', self::DEFAULT_RETRY_DELAY_MS);
        return max(0, $delay);
    }

    private function isTimeoutException(TransferException $e): bool
    {
        $message = strtolower($e->getMessage());
        if (str_contains($message, 'curl error 28') || str_contains($message, 'operation timed out') || str_contains($message, 'timed out')) {
            return true;
        }
        if (method_exists($e, 'getHandlerContext')) {
            $context = $e->getHandlerContext();
            if (is_array($context)) {
                $errno = (int) ($context['errno'] ?? 0);
                $timedOut = (bool) ($context['timed_out'] ?? false);
                if (28 === $errno || true === $timedOut) {
                    return true;
                }
            }
        }

        return false;
    }

    private function extractCookieValue(string $cookieHeader, string $cookieName): ?string
    {
        $parts = preg_split('/;\s*/', $cookieHeader);
        if (!is_array($parts)) {
            return null;
        }

        foreach ($parts as $part) {
            if (!is_string($part) || '' === $part || !str_contains($part, '=')) {
                continue;
            }
            [$name, $value] = array_map('trim', explode('=', $part, 2));
            if (strcasecmp($name, $cookieName) === 0 && '' !== $value) {
                return $value;
            }
        }

        return null;
    }

    private function normalizeRedirectPath(string $location, string $fallback): string
    {
        $location = trim($location);
        if ('' === $location) {
            return $fallback;
        }

        if (str_starts_with($location, 'http://') || str_starts_with($location, 'https://')) {
            $path  = (string)(parse_url($location, PHP_URL_PATH) ?? '');
            $query = (string)(parse_url($location, PHP_URL_QUERY) ?? '');
            if ('' === $path) {
                return $fallback;
            }

            return '' === $query ? $path : sprintf('%s?%s', $path, $query);
        }

        return str_starts_with($location, '/') ? $location : ('/' . ltrim($location, '/'));
    }
}
