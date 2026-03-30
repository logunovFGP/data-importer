<?php

declare(strict_types=1);

namespace App\Services\BasisBank\Request;

use App\Exceptions\ImporterHttpException;
use App\Services\BasisBank\Authentication\SecretManager;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use Psr\Http\Message\ResponseInterface;

/**
 * Shared web-session management methods for BasisBank request classes.
 *
 * Provides cookie handling, artifact encoding/decoding, CardModule communication,
 * HTML parsing helpers, and session recovery logic used by both GetAccountsRequest
 * and GetTransactionsRequest.
 *
 * Using classes MUST define:
 *   - private array $sessionCookies = [];
 *   - private bool $sessionCookiesLoaded = false;
 *   - private float $timeOut;
 *   - private readonly string $sessionArtifact;
 *
 * PHP 8.2+ trait constants used by the shared session logic.
 * Values match those in GetAccountsRequest / GetTransactionsRequest.
 */
trait BasisBankWebSessionTrait
{
    private const string BASE_WEB_URL                  = 'https://www.bankonline.ge';
    private const string CARD_MODULE_PATH              = '/Handlers/CardModule.ashx';
    private const string STATEMENT_PAGE_PATH           = '/Accounts/Statement/Statement.aspx';
    private const int    MAX_SESSION_RECOVERY_ATTEMPTS  = 1;
    private const string DATE_FORMAT                   = 'd/m/Y';

    // ------------------------------------------------------------------
    // Session artifact encoding / decoding
    // ------------------------------------------------------------------

    private function decodeArtifact(string $artifact): array
    {
        if ('' === trim($artifact)) {
            return [];
        }

        $decoded = base64_decode($artifact, true);
        if (false === $decoded) {
            $decoded = $artifact;
        }

        $data = json_decode((string)$decoded, true);
        if (!is_array($data)) {
            return [];
        }

        $cookies = [];
        if (array_is_list($data)) {
            foreach ($data as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $name = trim((string)($entry['name'] ?? ''));
                if ('' === $name) {
                    continue;
                }
                $cookies[$name] = (string)($entry['value'] ?? '');
            }

            return $cookies;
        }
        foreach ($data as $name => $value) {
            if ('' === trim((string)$name) || !is_string($value)) {
                continue;
            }
            $cookies[(string)$name] = (string)$value;
        }

        return $cookies;
    }

    private function encodeArtifact(array $cookies): string
    {
        return base64_encode((string)json_encode($cookies));
    }

    // ------------------------------------------------------------------
    // Cookie / session management
    // ------------------------------------------------------------------

    private function buildCookieHeader(array $cookies): string
    {
        $parts = [];
        foreach ($cookies as $name => $value) {
            if ('' === trim((string)$name)) {
                continue;
            }
            $parts[] = sprintf('%s=%s', (string)$name, (string)$value);
        }

        return implode('; ', $parts);
    }

    private function updateSessionCookiesFromResponse(ResponseInterface $response): void
    {
        if (false === $this->sessionCookiesLoaded) {
            $this->sessionCookies = $this->decodeArtifact($this->resolveSessionArtifact());
            $this->sessionCookiesLoaded = true;
        }
        foreach ($response->getHeader('Set-Cookie') as $setCookie) {
            $segments = explode(';', $setCookie);
            if ([] === $segments) {
                continue;
            }
            $cookiePair = trim((string)$segments[0]);
            if ('' === $cookiePair || !str_contains($cookiePair, '=')) {
                continue;
            }
            [$name, $value] = explode('=', $cookiePair, 2);
            $name = trim((string)$name);
            if ('' === $name) {
                continue;
            }
            $this->sessionCookies[$name] = trim((string)$value);
        }
        SecretManager::saveSessionArtifact($this->encodeArtifact($this->sessionCookies));
    }

    private function resolveSessionArtifact(): string
    {
        if ('' !== trim($this->sessionArtifact)) {
            return trim($this->sessionArtifact);
        }

        return trim(SecretManager::getSessionArtifact());
    }

    private function getSessionCookies(): array
    {
        if (false === $this->sessionCookiesLoaded) {
            $this->sessionCookies = $this->decodeArtifact($this->resolveSessionArtifact());
            $this->sessionCookiesLoaded = true;
        }

        return $this->sessionCookies;
    }

    // ------------------------------------------------------------------
    // HTML / payload inspection
    // ------------------------------------------------------------------

    private function containsLoginForm(string $html): bool
    {
        return str_contains($html, 'id="UTXT"') && str_contains($html, 'id="PTXT"');
    }

    private function isDeadSessionPayload(mixed $payload): bool
    {
        if (is_string($payload)) {
            return str_contains($payload, 'DeadSession');
        }
        if (is_array($payload)) {
            if (array_key_exists('Status', $payload) && str_contains((string)$payload['Status'], 'DeadSession')) {
                return true;
            }
            if (array_key_exists('status', $payload) && str_contains((string)$payload['status'], 'DeadSession')) {
                return true;
            }
        }

        return false;
    }

    private function coercePayload(mixed $payload): mixed
    {
        $current = $payload;
        for ($i = 0; $i < 3; $i++) {
            if (!is_string($current)) {
                break;
            }
            $trimmed = trim($current);
            if ('' === $trimmed || (!str_starts_with($trimmed, '{') && !str_starts_with($trimmed, '['))) {
                break;
            }
            $decoded = json_decode($trimmed, true);
            if (JSON_ERROR_NONE !== json_last_error()) {
                break;
            }
            $current = $decoded;
        }

        return $current;
    }

    // ------------------------------------------------------------------
    // Parsing helpers
    // ------------------------------------------------------------------

    private function parseAmountValue(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float)$value;
        }
        if (!is_string($value)) {
            return 0.0;
        }

        $normalized = trim(str_replace(["\u{00A0}", ' '], '', $value));
        $negative = str_starts_with($normalized, '(') && str_ends_with($normalized, ')');
        if ($negative) {
            $normalized = '-'.trim($normalized, '()');
        }
        $normalized = preg_replace('/[^0-9,.\-]/', '', $normalized);
        if (null === $normalized) {
            return 0.0;
        }
        $normalized = (string)$normalized;
        if ('' === $normalized || '-' === $normalized || '.' === $normalized || ',' === $normalized) {
            return 0.0;
        }

        $comma = strrpos($normalized, ',');
        $dot = strrpos($normalized, '.');
        if (false !== $comma && false !== $dot && $comma > $dot) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        } elseif (false !== $comma && false === $dot) {
            $normalized = str_replace(',', '.', $normalized);
        } else {
            $normalized = str_replace(',', '', $normalized);
        }
        $normalized = preg_replace('/\.\./', '.', (string)$normalized) ?? '0';

        return (float)$normalized;
    }

    private function normalizeWhitespace(string $value): string
    {
        return preg_replace('/\s+/u', ' ', trim($value)) ?: '';
    }

    private function normalizeDate(string $value): string
    {
        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Exception $e) {
            throw new \App\Exceptions\ImporterErrorException('Cannot parse date: ' . $value);
        }
    }

    private function extractStatementTransactionId(array $texts): string
    {
        foreach ($texts as $text) {
            $match = [];
            if (preg_match('/\b(RT\d{6,}|TR\d{6,}|TX\d{6,}|REF[- ]?\d{5,}|\d{10,})\b/i', $text, $match) === 1) {
                return strtoupper(trim((string)$match[1]));
            }
        }

        return '';
    }

    // ------------------------------------------------------------------
    // Payload row extraction
    // ------------------------------------------------------------------

    /**
     * Extract a flat list of rows from a CardModule payload.
     *
     * Consolidates the former extractRowsFromTransactionPayload, extractRowsFromPayload,
     * and extractArrayPayload into a single method. Handles transaction lists, card lists,
     * and any other CardModule response shape.
     */
    private function extractArrayPayload(mixed $payload): array
    {
        if (is_array($payload) && array_is_list($payload)) {
            return $payload;
        }
        if (!is_array($payload)) {
            return [];
        }

        foreach (['d', 'data', 'Data', 'result', 'Result', 'transactions', 'Transactions', 'items', 'Items', 'rows', 'Rows', 'List'] as $rootKey) {
            if (!isset($payload[$rootKey])) {
                continue;
            }
            $nested = $this->coercePayload($payload[$rootKey]);
            if (is_array($nested) && array_is_list($nested)) {
                return $nested;
            }
            if (!is_array($nested)) {
                continue;
            }
            foreach (['items', 'Items', 'rows', 'Rows', 'transactions', 'Transactions'] as $childKey) {
                if (!isset($nested[$childKey])) {
                    continue;
                }
                $deeper = $this->coercePayload($nested[$childKey]);
                if (is_array($deeper) && array_is_list($deeper)) {
                    return $deeper;
                }
            }
        }

        return [];
    }

    // ------------------------------------------------------------------
    // Session recovery
    // ------------------------------------------------------------------

    private function isSessionRecoveryCandidate(ImporterHttpException $e): bool
    {
        $statusCode = (int)$e->statusCode;
        if (in_array($statusCode, [302, 401, 403, 440], true)) {
            return true;
        }
        $message = strtolower($e->getMessage());
        $needles = [
            'session expired',
            'authentication required',
            'requires login',
            'returned login form',
            'not authorized',
            'deadsession',
            'web session is not available',
            'redirect location',
            'returned http 302',
        ];
        foreach ($needles as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Session recovery must NOT silently call BasisBankWebAuthClient::start(), because that
     * may trigger an SMS OTP — a rate-limited resource — without the user's knowledge or consent.
     * Instead, throw immediately so the UI can redirect to the authentication flow where the
     * user can explicitly choose to re-authenticate.
     *
     * @throws ImporterHttpException
     */
    private function recoverWebSessionForCardModule(string $funq, ImporterHttpException $trigger): void
    {
        logger()->warning(
            sprintf(
                'BasisBank CardModule session expired for "%s" after status %d: %s. '
                . 'Refusing automatic re-authentication to prevent silent SMS consumption.',
                $funq,
                (int)$trigger->statusCode,
                $trigger->getMessage()
            )
        );

        $httpException             = new ImporterHttpException(
            sprintf(
                'BasisBank session has expired for "%s". Please re-authenticate through the import flow. '
                . 'Automatic session recovery is disabled to prevent silent SMS/OTP consumption.',
                $funq
            )
        );
        $httpException->statusCode = (int)$trigger->statusCode;

        throw $httpException;
    }

    // ------------------------------------------------------------------
    // CardModule communication
    // ------------------------------------------------------------------

    private function callCardModule(string $funq, array $form): array
    {
        $cookies = $this->getSessionCookies();
        if ([] === $cookies) {
            throw new ImporterHttpException('BasisBank web session is not available for CardModule request.');
        }

        $client = new Client(
            [
                'base_uri'        => self::BASE_WEB_URL,
                'connect_timeout' => $this->timeOut,
                'timeout'         => $this->timeOut,
                'verify'          => config('importer.connection.verify'),
            ]
        );

        try {
            $response = $client->request(
                'POST',
                sprintf('%s?funq=%s', self::CARD_MODULE_PATH, urlencode($funq)),
                [
                    'headers' => [
                        'Accept'           => 'application/json, text/javascript, */*; q=0.01',
                        'Content-Type'     => 'application/x-www-form-urlencoded; charset=UTF-8',
                        'Referer'          => sprintf('%s/Products/Cards/Default.aspx', self::BASE_WEB_URL),
                        'User-Agent'       => sprintf('FF3-data-importer/%s (%s)', config('importer.version'), config('importer.line_b')),
                        'X-Requested-With' => 'XMLHttpRequest',
                        'Cookie'           => $this->buildCookieHeader($cookies),
                    ],
                    'form_params'     => $form,
                    'allow_redirects' => false,
                ]
            );
        } catch (TransferException $e) {
            $httpException             = new ImporterHttpException(sprintf('BasisBank CardModule request failed for "%s": %s', $funq, $e->getMessage()), 0, $e);
            $httpException->statusCode = method_exists($e, 'getResponse') && null !== $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
            throw $httpException;
        }

        $status = (int)$response->getStatusCode();
        $this->updateSessionCookiesFromResponse($response);
        $location = trim((string)$response->getHeaderLine('Location'));
        if (302 === $status) {
            $locationInfo = '' === $location ? '[empty Location header]' : $location;
            logger()->warning(
                sprintf(
                    'BasisBank CardModule redirect detected for "%s": HTTP 302 Location="%s".',
                    $funq,
                    $locationInfo
                )
            );
            if (str_contains(strtolower($location), 'login.aspx')) {
                $httpException             = new ImporterHttpException(sprintf('BasisBank CardModule returned HTTP 302 for "%s". Redirect Location: %s', $funq, $locationInfo));
                $httpException->statusCode = $status;
                throw $httpException;
            }
            if ('' === $location) {
                $httpException             = new ImporterHttpException(sprintf('BasisBank CardModule returned HTTP 302 for "%s" with empty redirect location.', $funq));
                $httpException->statusCode = $status;
                throw $httpException;
            }

            try {
                $redirectResponse = $client->request(
                    'GET',
                    $location,
                    [
                        'headers' => [
                            'Accept'           => 'application/json, text/javascript, */*; q=0.01',
                            'Referer'          => sprintf('%s/Products/Cards/Default.aspx', self::BASE_WEB_URL),
                            'User-Agent'       => sprintf('FF3-data-importer/%s (%s)', config('importer.version'), config('importer.line_b')),
                            'X-Requested-With' => 'XMLHttpRequest',
                            'Cookie'           => $this->buildCookieHeader($this->getSessionCookies()),
                        ],
                        'allow_redirects' => true,
                    ]
                );
            } catch (TransferException $e) {
                $httpException             = new ImporterHttpException(sprintf('BasisBank CardModule redirect follow-up failed for "%s": %s', $funq, $e->getMessage()), 0, $e);
                $httpException->statusCode = method_exists($e, 'getResponse') && null !== $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
                throw $httpException;
            }

            $redirectStatus = (int)$redirectResponse->getStatusCode();
            $this->updateSessionCookiesFromResponse($redirectResponse);
            if (401 === $redirectStatus || 403 === $redirectStatus || 440 === $redirectStatus) {
                $httpException             = new ImporterHttpException(sprintf('BasisBank web session expired for "%s" after redirect follow-up.', $funq));
                $httpException->statusCode = $redirectStatus;
                throw $httpException;
            }
            if ($redirectStatus < 200 || $redirectStatus >= 300) {
                $httpException             = new ImporterHttpException(sprintf('BasisBank CardModule redirect follow-up returned HTTP %d for "%s".', $redirectStatus, $funq));
                $httpException->statusCode = $redirectStatus;
                throw $httpException;
            }

            $redirectBody = trim((string)$redirectResponse->getBody());
            if ($this->isDeadSessionPayload($redirectBody)) {
                throw new ImporterHttpException(sprintf('BasisBank web session expired while requesting "%s" (redirect follow-up).', $funq));
            }
            if ($this->containsLoginForm($redirectBody)) {
                throw new ImporterHttpException(sprintf('BasisBank CardModule response requires login for "%s" (redirect follow-up).', $funq));
            }
            if ('' === $redirectBody || 'null' === strtolower($redirectBody)) {
                return [];
            }
            $redirectDecoded = $this->coercePayload($redirectBody);
            if (!is_array($redirectDecoded)) {
                throw new ImporterHttpException(sprintf('BasisBank CardModule redirect follow-up response for "%s" is not JSON.', $funq));
            }
            logger()->warning(sprintf('BasisBank CardModule redirect for "%s" was followed successfully (HTTP %d).', $funq, $redirectStatus));

            return $redirectDecoded;
        }
        if (401 === $status || 403 === $status || 440 === $status) {
            $httpException             = new ImporterHttpException(sprintf('BasisBank web session expired for "%s".', $funq));
            $httpException->statusCode = $status;
            throw $httpException;
        }
        if ($status < 200 || $status >= 300) {
            $httpException             = new ImporterHttpException(sprintf('BasisBank CardModule returned HTTP %d for "%s".', $status, $funq));
            $httpException->statusCode = $status;
            throw $httpException;
        }

        $body = trim((string)$response->getBody());
        if ($this->isDeadSessionPayload($body)) {
            throw new ImporterHttpException(sprintf('BasisBank web session expired while requesting "%s".', $funq));
        }
        if ($this->containsLoginForm($body)) {
            throw new ImporterHttpException(sprintf('BasisBank CardModule response requires login for "%s".', $funq));
        }

        if ('' === $body || 'null' === strtolower($body)) {
            return [];
        }
        $decoded = $this->coercePayload($body);
        if (!is_array($decoded)) {
            throw new ImporterHttpException(sprintf('BasisBank CardModule response for "%s" is not JSON.', $funq));
        }

        return $decoded;
    }

    /**
     * @throws ImporterHttpException
     */
    private function callCardModuleWithSessionRecovery(string $funq, array $form): array
    {
        $attempt = 0;
        while (true) {
            try {
                return $this->callCardModule($funq, $form);
            } catch (ImporterHttpException $e) {
                if (!$this->isSessionRecoveryCandidate($e) || $attempt >= self::MAX_SESSION_RECOVERY_ATTEMPTS) {
                    throw $e;
                }
                $attempt++;
                $this->recoverWebSessionForCardModule($funq, $e);
            }
        }
    }

    // ------------------------------------------------------------------
    // Statement page retrieval
    // ------------------------------------------------------------------

    /**
     * @throws ImporterHttpException
     */
    private function requestStatementPage(string $statementId, ?string $dateFrom = null, ?string $dateTo = null): string
    {
        $cookies = $this->getSessionCookies();
        if ([] === $cookies) {
            throw new ImporterHttpException('BasisBank web session is not available for statement-page retrieval.');
        }

        $client = new Client(
            [
                'base_uri'        => self::BASE_WEB_URL,
                'connect_timeout' => $this->timeOut,
                'timeout'         => $this->timeOut,
                'verify'          => config('importer.connection.verify'),
            ]
        );

        // The initial GET MUST include date params in the URL.
        // Without them, the server returns a 42KB page with empty GridView1 and a tiny ViewState (~3KB).
        // With them, the server pre-populates the grid and returns a 176KB page with full ViewState (~59KB).
        // Proven via Playwright recording: browser navigates to Statement.aspx?ID=X&StartDay=D&StartMounth=M&StartYear=Y
        // When dateFrom is null ("import all" mode), use the earliest date the bank supports.
        // The Statement.aspx dropdown starts at year 2000. Using 2-year fallback would miss older data.
        $getStart = null !== $dateFrom && '' !== trim((string)$dateFrom)
            ? Carbon::parse($dateFrom) : Carbon::create(2000, 1, 1);
        $getEnd = null !== $dateTo && '' !== trim((string)$dateTo)
            ? Carbon::parse($dateTo) : Carbon::now();
        $getUrl = sprintf(
            '%s?ID=%s&StartDay=%s&StartMounth=%s&StartYear=%s',
            self::STATEMENT_PAGE_PATH,
            urlencode($statementId),
            (string)(int)$getStart->format('d'),
            (string)(int)$getStart->format('m'),
            $getStart->format('Y')
        );

        try {
            $response = $client->request(
                'GET',
                $getUrl,
                [
                    'headers'         => [
                        'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                        'Cookie'     => $this->buildCookieHeader($cookies),
                        'Referer'    => sprintf('%s/Balance.aspx', self::BASE_WEB_URL),
                        'User-Agent' => sprintf('FF3-data-importer/%s (%s)', config('importer.version'), config('importer.line_b')),
                    ],
                    'allow_redirects' => false,
                ]
            );
        } catch (TransferException $e) {
            $httpException = new ImporterHttpException(sprintf('BasisBank statement-page request failed for account "%s": %s', $statementId, $e->getMessage()), 0, $e);
            $httpException->statusCode = method_exists($e, 'getResponse') && null !== $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
            throw $httpException;
        }

        $status = (int)$response->getStatusCode();
        $this->updateSessionCookiesFromResponse($response);
        $location = trim((string)$response->getHeaderLine('Location'));
        if (302 === $status) {
            if (str_contains(strtolower($location), 'login.aspx')) {
                $httpException = new ImporterHttpException(sprintf('BasisBank statement page requires login for account "%s".', $statementId));
                $httpException->statusCode = $status;
                throw $httpException;
            }
            if ('' === $location) {
                $httpException = new ImporterHttpException(sprintf('BasisBank statement page returned HTTP 302 with empty location for account "%s".', $statementId));
                $httpException->statusCode = $status;
                throw $httpException;
            }
            try {
                $redirectResponse = $client->request(
                    'GET',
                    $location,
                    [
                        'headers'         => [
                            'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                            'Cookie'     => $this->buildCookieHeader($this->getSessionCookies()),
                            'Referer'    => sprintf('%s%s?ID=%s', self::BASE_WEB_URL, self::STATEMENT_PAGE_PATH, urlencode($statementId)),
                            'User-Agent' => sprintf('FF3-data-importer/%s (%s)', config('importer.version'), config('importer.line_b')),
                        ],
                        'allow_redirects' => true,
                    ]
                );
            } catch (TransferException $e) {
                $httpException = new ImporterHttpException(sprintf('BasisBank statement-page redirect follow-up failed for account "%s": %s', $statementId, $e->getMessage()), 0, $e);
                $httpException->statusCode = method_exists($e, 'getResponse') && null !== $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
                throw $httpException;
            }
            $redirectStatus = (int)$redirectResponse->getStatusCode();
            $this->updateSessionCookiesFromResponse($redirectResponse);
            if (401 === $redirectStatus || 403 === $redirectStatus || 440 === $redirectStatus) {
                $httpException = new ImporterHttpException(sprintf('BasisBank statement page requires login for account "%s" after redirect.', $statementId));
                $httpException->statusCode = $redirectStatus;
                throw $httpException;
            }
            if ($redirectStatus < 200 || $redirectStatus >= 300) {
                $httpException = new ImporterHttpException(sprintf('BasisBank statement-page redirect follow-up returned HTTP %d for account "%s".', $redirectStatus, $statementId));
                $httpException->statusCode = $redirectStatus;
                throw $httpException;
            }

            $redirectHtml = (string)$redirectResponse->getBody();
            if ($this->containsLoginForm($redirectHtml)) {
                throw new ImporterHttpException(sprintf('BasisBank statement page returned login form for account "%s".', $statementId));
            }

            return $redirectHtml;
        }
        if (401 === $status || 403 === $status || 440 === $status) {
            $httpException = new ImporterHttpException(sprintf('BasisBank statement page denied access (%d) for account "%s".', $status, $statementId));
            $httpException->statusCode = $status;
            throw $httpException;
        }
        if ($status >= 300) {
            $httpException = new ImporterHttpException(sprintf('BasisBank statement page returned HTTP %d for account "%s".', $status, $statementId));
            $httpException->statusCode = $status;
            throw $httpException;
        }

        $html = (string)$response->getBody();
        if ($this->containsLoginForm($html)) {
            throw new ImporterHttpException(sprintf('BasisBank statement page returned login form for account "%s".', $statementId));
        }

        return $this->hydrateStatementPageWithPostback($client, $statementId, $html, $dateFrom, $dateTo);
    }

    private function hydrateStatementPageWithPostback(Client $client, string $statementId, string $html, ?string $dateFrom = null, ?string $dateTo = null): string
    {
        $payload = $this->buildStatementPostPayload($html, $statementId, $dateFrom, $dateTo);
        if ([] === $payload) {
            return $html;
        }

        // Build URL with date params matching the browser's pattern:
        // Statement.aspx?ID=1608515&StartDay=26&StartMounth=3&StartYear=2026
        // The bank expects dates in both the URL query string AND the form body.
        $start = null !== $dateFrom && '' !== trim((string)$dateFrom)
            ? Carbon::parse($dateFrom) : Carbon::create(2000, 1, 1);
        $end = null !== $dateTo && '' !== trim((string)$dateTo)
            ? Carbon::parse($dateTo) : Carbon::now();
        $postUrl = sprintf(
            '%s?ID=%s&StartDay=%s&StartMounth=%s&StartYear=%s',
            self::STATEMENT_PAGE_PATH,
            urlencode($statementId),
            (string)(int)$start->format('d'),
            (string)(int)$start->format('m'),
            $start->format('Y')
        );

        logger()->debug(sprintf('BasisBank statement POST URL for account "%s": %s (payload fields: %d)',
            $statementId,
            $postUrl,
            count($payload)
        ));

        try {
            $response = $client->request(
                'POST',
                $postUrl,
                [
                    'headers' => [
                        'Accept'       => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                        'Cookie'       => $this->buildCookieHeader($this->getSessionCookies()),
                        'Referer'      => sprintf('%s%s', self::BASE_WEB_URL, $postUrl),
                        'User-Agent'   => sprintf('FF3-data-importer/%s (%s)', config('importer.version'), config('importer.line_b')),
                        'Content-Type' => 'application/x-www-form-urlencoded',
                    ],
                    'form_params'     => $payload,
                    'allow_redirects' => false,
                ]
            );
        } catch (TransferException $e) {
            logger()->warning(
                sprintf(
                    'BasisBank statement postback failed for account "%s": %s',
                    $statementId,
                    $e->getMessage()
                )
            );

            return $html;
        }

        $status = (int)$response->getStatusCode();
        $this->updateSessionCookiesFromResponse($response);
        $location = trim((string)$response->getHeaderLine('Location'));
        if (302 === $status) {
            if ('' === $location || str_contains(strtolower($location), 'login.aspx')) {
                return $html;
            }
            try {
                $redirectResponse = $client->request(
                    'GET',
                    $location,
                    [
                        'headers' => [
                            'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                            'Cookie'     => $this->buildCookieHeader($this->getSessionCookies()),
                            'Referer'    => sprintf('%s%s?ID=%s', self::BASE_WEB_URL, self::STATEMENT_PAGE_PATH, urlencode($statementId)),
                            'User-Agent' => sprintf('FF3-data-importer/%s (%s)', config('importer.version'), config('importer.line_b')),
                        ],
                        'allow_redirects' => true,
                    ]
                );
            } catch (TransferException $e) {
                logger()->warning(
                    sprintf(
                        'BasisBank statement postback redirect failed for account "%s": %s',
                        $statementId,
                        $e->getMessage()
                    )
                );

                return $html;
            }
            $this->updateSessionCookiesFromResponse($redirectResponse);
            $redirectHtml = (string)$redirectResponse->getBody();
            if ('' === trim($redirectHtml) || $this->containsLoginForm($redirectHtml)) {
                return $html;
            }

            return $redirectHtml;
        }
        if (401 === $status || 403 === $status || 440 === $status || $status >= 300) {
            return $html;
        }

        $postedHtml = (string)$response->getBody();
        if ('' === trim($postedHtml) || $this->containsLoginForm($postedHtml)) {
            return $html;
        }

        return $postedHtml;
    }

    /**
     * @return array<string, string>
     */
    private function buildStatementPostPayload(string $html, string $statementId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $document = new \DOMDocument();
        $loaded = @ $document->loadHTML($html);
        if (false === $loaded) {
            return [];
        }
        $xpath = new \DOMXPath($document);
        $payload = [];

        $inputs = $xpath->query('//form//input[@name]');
        if (false !== $inputs) {
            foreach ($inputs as $input) {
                if (!$input instanceof \DOMElement) {
                    continue;
                }
                $name = trim((string)$input->getAttribute('name'));
                if ('' === $name) {
                    continue;
                }
                $type = strtolower(trim((string)$input->getAttribute('type')));
                if (in_array($type, ['checkbox', 'radio'], true) && '' === trim((string)$input->getAttribute('checked'))) {
                    continue;
                }
                $payload[$name] = (string)$input->getAttribute('value');
            }
        }

        $selects = $xpath->query('//form//select[@name]');
        if (false !== $selects) {
            foreach ($selects as $select) {
                if (!$select instanceof \DOMElement) {
                    continue;
                }
                $name = trim((string)$select->getAttribute('name'));
                if ('' === $name) {
                    continue;
                }
                $selectedValue = '';
                $selected = $xpath->query('.//option[@selected]', $select);
                if (false !== $selected && $selected->length > 0) {
                    $option = $selected->item(0);
                    if ($option instanceof \DOMElement) {
                        $selectedValue = trim((string)$option->getAttribute('value'));
                    }
                }
                if ('' === $selectedValue) {
                    $options = $xpath->query('.//option', $select);
                    if (false !== $options && $options->length > 0) {
                        $option = $options->item(0);
                        if ($option instanceof \DOMElement) {
                            $selectedValue = trim((string)$option->getAttribute('value'));
                        }
                    }
                }
                $payload[$name] = $selectedValue;
            }
        }

        if (!array_key_exists('__VIEWSTATE', $payload) || '' === trim((string)$payload['__VIEWSTATE'])) {
            return [];
        }

        foreach (array_keys($payload) as $key) {
            if (str_contains($key, 'AccountDDL')) {
                $payload[$key] = $statementId;
            }
        }
        if (!isset($payload['ctl00$Content$AccountDDL'])) {
            $payload['ctl00$Content$AccountDDL'] = $statementId;
        }

        // Use caller's date range if provided; fall back to earliest date the bank dropdown supports (year 2000).
        // "Import all" passes null dateFrom — using subYears(2) would miss older transactions.
        $historyYears = (int) config('basisbank.statement_history_years', 25);
        try {
            $start = null !== $dateFrom && '' !== trim((string)$dateFrom)
                ? Carbon::parse($dateFrom)
                : Carbon::now()->subYears($historyYears);
        } catch (\Exception) {
            $start = Carbon::now()->subYears($historyYears);
        }
        try {
            $end = null !== $dateTo && '' !== trim((string)$dateTo)
                ? Carbon::parse($dateTo)
                : Carbon::now();
        } catch (\Exception) {
            $end = Carbon::now();
        }

        // Resolve form field names dynamically — BasisBank uses "mounth" (their typo, not ours).
        $defaults = [
            'ctl00$Content$CurDateTxt'     => $start->format(self::DATE_FORMAT),
            'ctl00$Content$CurDateTxtEnd'  => $end->format(self::DATE_FORMAT),
            'ctl00$Content$DDLday'         => (string)(int)$start->format('d'),
            'ctl00$Content$DDLmounth'      => (string)(int)$start->format('m'),
            'ctl00$Content$DDLyear'        => $start->format('Y'),
            'ctl00$Content$DDLdayEnd'      => (string)(int)$end->format('d'),
            'ctl00$Content$DDLmounthEnd'   => (string)(int)$end->format('m'),
            'ctl00$Content$DDLyearEnd'     => $end->format('Y'),
            'ctl00$Content$ReportType'     => '3',
            '__EVENTTARGET'                => '',
            '__EVENTARGUMENT'              => '',
        ];
        // Unconditionally set all defaults — ASP.NET expects ALL form fields in the POST,
        // even if they weren't rendered as visible HTML elements in the GET response.
        foreach ($defaults as $key => $value) {
            $payload[$key] = (string)$value;
        }
        // Submit button: proven via browser DevTools capture (2026-03-26).
        // NAME: ctl00$Content$Button2  VALUE: Re-count  TYPE: submit  ID: Content_Button2
        // Without this field, ASP.NET does a no-op postback and returns an empty grid.
        $payload['ctl00$Content$Button2'] = 'Re-count';

        return $payload;
    }

    /**
     * @throws ImporterHttpException
     */
    private function requestStatementPageWithSessionRecovery(string $statementId, ?string $dateFrom = null, ?string $dateTo = null): string
    {
        $attempt = 0;
        while (true) {
            try {
                return $this->requestStatementPage($statementId, $dateFrom, $dateTo);
            } catch (ImporterHttpException $e) {
                if (!$this->isSessionRecoveryCandidate($e) || $attempt >= self::MAX_SESSION_RECOVERY_ATTEMPTS) {
                    throw $e;
                }
                $attempt++;
                $this->recoverWebSessionForCardModule(sprintf('statement-%s', $statementId), $e);
            }
        }
    }
}
