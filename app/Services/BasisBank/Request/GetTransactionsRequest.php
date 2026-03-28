<?php

declare(strict_types=1);

namespace App\Services\BasisBank\Request;

use App\Exceptions\ImporterErrorException;
use App\Exceptions\ImporterHttpException;
use App\Services\BasisBank\Auth\BasisBankWebAuthClient;
use App\Services\BasisBank\Authentication\SecretManager;
use App\Services\LunchFlow\Response\GetTransactionsResponse;
use App\Services\Shared\Request\BearerJsonRequest;
use App\Services\Shared\Support\CurrencyCode;
use Carbon\Carbon;
use DOMElement;
use DOMXPath;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;

class GetTransactionsRequest extends BearerJsonRequest
{
    private const string BASE_WEB_URL     = 'https://www.bankonline.ge';
    private const string CARD_MODULE_PATH = '/Handlers/CardModule.ashx';
    private const string STATEMENT_PAGE_PATH = '/Accounts/Statement/Statement.aspx';
    private const float  TIMEOUT_SECONDS = 3.14;
    private const int    DEFAULT_MAX_PAGES = 120;
    private const int    DEFAULT_PAGE_SIZE = 20;
    private const int    MAX_RETRY_ATTEMPTS = 4;
    private const int    BASE_RETRY_DELAY_MS = 450;
    private const int    MAX_SESSION_RECOVERY_ATTEMPTS = 1;
    private const string DATE_FORMAT = 'd/m/Y';

    private float $timeOut = self::TIMEOUT_SECONDS;
    private array $sessionCookies = [];
    private bool $sessionCookiesLoaded = false;
    private static array $webSessionRowsCache = [];
    private mixed $progressReporter = null;

    public function __construct(
        private readonly string $apiToken,
        private readonly string $consentId,
        private readonly string $accountId,
        private readonly string $sessionArtifact = ''
    ) {
        parent::__construct((string)config('basisbank.api_url'), $apiToken);
    }

    public function setTimeOut(float $timeOut): void
    {
        $this->timeOut = $timeOut;
        parent::setTimeOut($timeOut);
    }

    public function setProgressReporter(?callable $progressReporter): void
    {
        $this->progressReporter = $progressReporter;
    }

    /**
     * @throws ImporterHttpException
     */
    public function get(?string $dateFrom = null, ?string $dateTo = null): GetTransactionsResponse
    {
        if (true === config('importer.fake_data')) {
            $response = new GetTransactionsResponse(
                [
                    [
                        'id'          => sprintf('basisbank-%s-1', $this->accountId),
                        'accountId'   => $this->accountId,
                        'amount'      => '-25.50',
                        'currency'    => 'GEL',
                        'date'        => '2025-10-15',
                        'merchant'    => 'BasisBank Demo Merchant',
                        'description' => 'BasisBank demo transaction',
                    ],
                ]
            );
            $response->processData();

            return $response;
        }

        if ('' !== trim($this->resolveSessionArtifact())) {
            return $this->getFromWebSession($dateFrom, $dateTo);
        }

        if ('' === trim($this->apiToken) || '' === trim($this->consentId)) {
            throw new ImporterHttpException('BasisBank transaction retrieval requires a valid web session artifact or API token/consent-id.');
        }

        $path      = str_replace('{account_id}', urlencode($this->getBaseAccountId()), (string)config('basisbank.transactions_endpoint'));
        $query     = [];
        if ('' !== (string)$dateFrom) {
            $query['dateFrom'] = $dateFrom;
            $query['fromDate'] = $dateFrom;
        }
        if ('' !== (string)$dateTo) {
            $query['dateTo'] = $dateTo;
            $query['toDate'] = $dateTo;
        }

        $payload    = $this->getJson($path, $this->headers(), $query);
        $rows       = $this->extractRows($payload);
        $normalized = [];
        foreach ($rows as $row) {
            $normalized[] = $this->normalizeTransaction($row);
        }

        $response  = new GetTransactionsResponse($normalized);
        $response->processData();

        return $response;
    }

    /**
     * Resolve account currency from up to $sampleSize transactions.
     *
     * @throws ImporterHttpException
     */
    public function detectAccountCurrency(?string $dateFrom = null, ?string $dateTo = null, int $sampleSize = 10): string
    {
        return $this->selectDominantCurrency($this->sampleAccountCurrencies($dateFrom, $dateTo, $sampleSize));
    }

    /**
     * @return array<string>
     * @throws ImporterHttpException
     */
    public function sampleAccountCurrencies(?string $dateFrom = null, ?string $dateTo = null, int $sampleSize = 10): array
    {
        $limit      = max(1, $sampleSize);
        $currencies = [];

        if ('' !== trim($this->resolveSessionArtifact())) {
            $rows = $this->fetchSampleWebSessionRows($dateFrom, $dateTo, $limit);
            foreach ($rows as $row) {
                if (!is_array($row) || !$this->matchesConfiguredAccount($row)) {
                    continue;
                }
                $normalized = $this->normalizeTransaction($row);
                $currency   = CurrencyCode::normalizeOrEmpty((string)($normalized['currency'] ?? ''));
                if ('' === $currency) {
                    continue;
                }
                $currencies[] = $currency;
                if (count($currencies) >= $limit) {
                    break;
                }
            }

            return $currencies;
        }

        if ('' === trim($this->apiToken) || '' === trim($this->consentId)) {
            throw new ImporterHttpException('BasisBank currency discovery requires a valid web session artifact or API token/consent-id.');
        }

        $path   = str_replace('{account_id}', urlencode($this->getBaseAccountId()), (string)config('basisbank.transactions_endpoint'));
        $query  = [];
        if ('' !== (string)$dateFrom) {
            $query['dateFrom'] = $dateFrom;
            $query['fromDate'] = $dateFrom;
        }
        if ('' !== (string)$dateTo) {
            $query['dateTo'] = $dateTo;
            $query['toDate'] = $dateTo;
        }

        $payload = $this->getJson($path, $this->headers(), $query);
        $rows    = $this->extractRows($payload);
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $normalized = $this->normalizeTransaction($row);
            $currency   = CurrencyCode::normalizeOrEmpty((string)($normalized['currency'] ?? ''));
            if ('' === $currency) {
                continue;
            }
            $currencies[] = $currency;
            if (count($currencies) >= $limit) {
                break;
            }
        }

        return $currencies;
    }

    private function getFromWebSession(?string $dateFrom = null, ?string $dateTo = null): GetTransactionsResponse
    {
        $normalized = [];
        try {
            $rows = $this->fetchAllWebSessionTransactions($dateFrom, $dateTo);
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if (!$this->matchesConfiguredAccount($row)) {
                    continue;
                }
                $normalized[] = $this->normalizeTransaction($row);
            }
        } catch (ImporterHttpException $e) {
            if (!$this->shouldUseStatementFallback($this->getBaseAccountId(), false, $e)) {
                throw $e;
            }
            Log::warning(
                sprintf(
                    'BasisBank fallback to statement-page parsing for account "%s" after CardModule error: %s',
                    $this->accountId,
                    $e->getMessage()
                )
            );
        }

        if ([] === $normalized) {
            foreach ($this->fetchStatementTransactions($dateFrom, $dateTo) as $statementRow) {
                if (!$this->matchesConfiguredAccount($statementRow)) {
                    continue;
                }
                $normalized[] = $this->normalizeTransaction($statementRow);
            }
        }

        $response = new GetTransactionsResponse($normalized);
        $response->processData();
        return $response;
    }

    private function fetchSampleWebSessionRows(?string $dateFrom, ?string $dateTo, int $limit): array
    {
        $sampleLimit = max(1, $limit);
        $baseAccountId = $this->getBaseAccountId();
        $rows = $this->fetchPagedTransactionsForAccountQuery($dateFrom, $dateTo, false, $this->accountId, $sampleLimit);
        if ($baseAccountId !== $this->accountId && count($rows) < $sampleLimit) {
            $rows = $this->mergeUniqueRows(
                $rows,
                $this->fetchPagedTransactionsForAccountQuery($dateFrom, $dateTo, false, $baseAccountId, $sampleLimit)
            );
        }
        if (count($rows) < $sampleLimit) {
            try {
                $pendingRows = $this->fetchPagedTransactionsForAccountQuery(
                    $dateFrom,
                    $dateTo,
                    true,
                    $baseAccountId,
                    $sampleLimit - count($rows)
                );
                $rows = $this->mergeUniqueRows($rows, $pendingRows);
            } catch (ImporterHttpException $e) {
                Log::warning(
                    sprintf(
                        'BasisBank pending-transaction sampling skipped for "%s": %s',
                        $baseAccountId,
                        $e->getMessage()
                    )
                );
            }
        }
        if (count($rows) < $sampleLimit) {
            $rows = $this->mergeUniqueRows(
                $rows,
                $this->fetchStatementTransactions($dateFrom, $dateTo, $sampleLimit - count($rows))
            );
        }

        return array_slice($rows, 0, $sampleLimit);
    }

    private function selectDominantCurrency(array $currencies): string
    {
        if ([] === $currencies) {
            return '';
        }

        $counter = [];
        foreach ($currencies as $currency) {
            if (!is_string($currency) || '' === trim($currency)) {
                continue;
            }
            $normalized = CurrencyCode::normalizeOrEmpty($currency);
            if ('' === $normalized) {
                continue;
            }
            if (!array_key_exists($normalized, $counter)) {
                $counter[$normalized] = 0;
            }
            $counter[$normalized]++;
        }
        if ([] === $counter) {
            return '';
        }
        arsort($counter);
        $best = array_key_first($counter);

        return is_string($best) ? $best : '';
    }

    private function fetchAllWebSessionTransactions(?string $dateFrom, ?string $dateTo): array
    {
        $cacheKey = $this->buildWebSessionCacheKey($dateFrom, $dateTo);
        if (isset(self::$webSessionRowsCache[$cacheKey])) {
            return self::$webSessionRowsCache[$cacheKey];
        }

        $booked = $this->fetchPagedTransactions($dateFrom, $dateTo, false);
        $pending = $this->fetchPagedTransactions($dateFrom, $dateTo, true);
        $rows = array_merge($booked, $pending);
        self::$webSessionRowsCache[$cacheKey] = $rows;

        return $rows;
    }

    private function buildWebSessionCacheKey(?string $dateFrom, ?string $dateTo): string
    {
        $token = trim($this->resolveSessionArtifact());
        if ('' === $token) {
            $token = trim($this->apiToken);
        }

        return hash('sha256', implode('|', ['basisbank', $token, (string)$dateFrom, (string)$dateTo]));
    }

    private function fetchPagedTransactions(?string $dateFrom, ?string $dateTo, bool $blockedOnly, ?int $limit = null): array
    {
        return $this->fetchPagedTransactionsForAccountQuery($dateFrom, $dateTo, $blockedOnly, '', $limit);
    }

    private function fetchPagedTransactionsForAccountQuery(?string $dateFrom, ?string $dateTo, bool $blockedOnly, string $accountQuery, ?int $limit = null): array
    {
        $startDate = $this->formatDateOrNull($dateFrom) ?? Carbon::now()->subYears(2)->format(self::DATE_FORMAT);
        $endDate = $this->formatDateOrNull($dateTo) ?? Carbon::now()->format(self::DATE_FORMAT);
        $rows = [];
        $signatures = [];
        $seen = [];
        $effectiveAccountQuery = '' !== trim($accountQuery) ? $accountQuery : $this->accountId;
        $this->emitProgress([
            'stage'         => 'cardmodule_start',
            'account_query' => $effectiveAccountQuery,
            'blocked_only'  => $blockedOnly,
            'fetched_total' => 0,
            'max_pages'     => self::DEFAULT_MAX_PAGES,
            'limit'         => $limit,
        ]);

        try {
            for ($page = 1; $page <= self::DEFAULT_MAX_PAGES; $page++) {
                $payload = $this->callCardModuleWithRetry('getlasttransactionlist', [
                    'StartDate'   => $startDate,
                    'EndDate'     => $endDate,
                    'SearchWord'  => '',
                    'PageNumber'  => (string)$page,
                    'JustBlocked' => $blockedOnly ? '1' : '0',
                    'AccountIban' => $accountQuery,
                ]);

                if (!is_array($payload)) {
                    break;
                }

                $pageRows = $this->extractRowsFromPayload($payload);
                if ([] === $pageRows) {
                    $this->emitProgress([
                        'stage'         => 'cardmodule_page',
                        'account_query' => $effectiveAccountQuery,
                        'blocked_only'  => $blockedOnly,
                        'page'          => $page,
                        'page_rows'     => 0,
                        'fetched_total' => count($rows),
                        'max_pages'     => self::DEFAULT_MAX_PAGES,
                        'limit'         => $limit,
                    ]);
                    break;
                }

                $currentCount = 0;
                $firstId = '';
                $lastId = '';
                foreach ($pageRows as $pageRow) {
                    if (!is_array($pageRow)) {
                        continue;
                    }
                    $rowId = (string)($pageRow['TransactionID'] ?? $pageRow['TransactionReference'] ?? $pageRow['TransferID'] ?? '');
                    if ('' === $firstId) {
                        $firstId = $rowId;
                    }
                    if ('' !== $rowId) {
                        $lastId = $rowId;
                    }
                    if ('' !== $rowId && isset($seen[$rowId])) {
                        continue;
                    }
                    if ('' !== $rowId) {
                        $seen[$rowId] = true;
                    }
                    $rows[] = $pageRow;
                    $currentCount++;
                    if (null !== $limit && count($rows) >= $limit) {
                        break;
                    }
                }

                if (0 === $currentCount) {
                    $this->emitProgress([
                        'stage'         => 'cardmodule_page',
                        'account_query' => $effectiveAccountQuery,
                        'blocked_only'  => $blockedOnly,
                        'page'          => $page,
                        'page_rows'     => 0,
                        'fetched_total' => count($rows),
                        'max_pages'     => self::DEFAULT_MAX_PAGES,
                        'limit'         => $limit,
                    ]);
                    break;
                }
                $this->emitProgress([
                    'stage'         => 'cardmodule_page',
                    'account_query' => $effectiveAccountQuery,
                    'blocked_only'  => $blockedOnly,
                    'page'          => $page,
                    'page_rows'     => $currentCount,
                    'fetched_total' => count($rows),
                    'max_pages'     => self::DEFAULT_MAX_PAGES,
                    'limit'         => $limit,
                ]);
                if (null !== $limit && count($rows) >= $limit) {
                    break;
                }
                if ($currentCount < self::DEFAULT_PAGE_SIZE) {
                    break;
                }

                $signature = sprintf(
                    '%s|%s|%s|%s',
                    $blockedOnly ? 'blocked' : 'booked',
                    $currentCount,
                    $firstId,
                    $lastId
                );
                if (isset($signatures[$signature])) {
                    break;
                }
                $signatures[$signature] = true;
            }
        } catch (ImporterHttpException $e) {
            if (!$this->shouldUseStatementFallback($accountQuery, $blockedOnly, $e)) {
                throw $e;
            }
            $this->emitProgress([
                'stage'         => 'statement_fallback',
                'account_query' => $effectiveAccountQuery,
                'blocked_only'  => $blockedOnly,
                'reason'        => $e->getMessage(),
                'fetched_total' => count($rows),
            ]);
            Log::warning(
                sprintf(
                    'BasisBank account-query fallback to statement-page parser for "%s" (blockedOnly=%s): %s',
                    $accountQuery,
                    $blockedOnly ? 'yes' : 'no',
                    $e->getMessage()
                )
            );

            return $this->fetchStatementTransactions($dateFrom, $dateTo, $limit, $accountQuery);
        }

        if ([] === $rows && $this->shouldAttemptStatementFallback($accountQuery, $blockedOnly)) {
            $this->emitProgress([
                'stage'         => 'statement_fallback',
                'account_query' => $effectiveAccountQuery,
                'blocked_only'  => $blockedOnly,
                'reason'        => 'CardModule returned no rows',
                'fetched_total' => 0,
            ]);

            return $this->fetchStatementTransactions($dateFrom, $dateTo, $limit, $accountQuery);
        }

        $this->emitProgress([
            'stage'         => 'cardmodule_done',
            'account_query' => $effectiveAccountQuery,
            'blocked_only'  => $blockedOnly,
            'fetched_total' => count($rows),
            'max_pages'     => self::DEFAULT_MAX_PAGES,
            'limit'         => $limit,
        ]);

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchStatementTransactions(
        ?string $dateFrom,
        ?string $dateTo,
        ?int $limit = null,
        string $accountQuery = ''
    ): array {
        $statementId = $this->resolveStatementIdFromAccountQuery($accountQuery);
        if ('' === $statementId) {
            return [];
        }
        $effectiveAccountQuery = '' !== trim($accountQuery) ? $accountQuery : $this->accountId;
        $this->emitProgress([
            'stage'         => 'statement_start',
            'account_query' => $effectiveAccountQuery,
            'statement_id'  => $statementId,
            'limit'         => $limit,
        ]);

        $html = $this->requestStatementPageWithSessionRecovery($statementId, $dateFrom, $dateTo);
        $rows = $this->parseStatementTransactionsFromHtml($html, $statementId, $dateFrom, $dateTo, $limit);
        if ([] === $rows) {
            Log::warning(sprintf('BasisBank statement fallback for account "%s" returned zero parsed transaction rows.', $statementId));
        }
        $this->emitProgress([
            'stage'         => 'statement_done',
            'account_query' => $effectiveAccountQuery,
            'statement_id'  => $statementId,
            'parsed_rows'   => count($rows),
            'limit'         => $limit,
        ]);

        return $rows;
    }

    private function shouldAttemptStatementFallback(string $accountQuery, bool $blockedOnly): bool
    {
        if ($blockedOnly) {
            return false;
        }

        return '' !== $this->resolveStatementIdFromAccountQuery($accountQuery);
    }

    private function shouldUseStatementFallback(string $accountQuery, bool $blockedOnly, ImporterHttpException $e): bool
    {
        if (!$this->shouldAttemptStatementFallback($accountQuery, $blockedOnly)) {
            return false;
        }

        $status = (int)$e->statusCode;
        if (in_array($status, [401, 403, 440], true)) {
            return false;
        }

        $message = strtolower($e->getMessage());
        if (str_contains($message, 'requires re-authentication')) {
            return false;
        }
        if (str_contains($message, 'authentication required')) {
            return false;
        }

        $needles = [
            'not json',
            'redirect follow-up response',
            'returned http 302',
            'redirect detected',
            'returned login form',
            'response requires login',
            'cardmodule response',
        ];
        foreach ($needles as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return 302 === $status;
    }

    private function resolveStatementIdFromAccountQuery(string $accountQuery): string
    {
        $raw = trim($accountQuery);
        if ('' === $raw) {
            $raw = $this->getBaseAccountId();
        }
        if ('' === $raw) {
            return '';
        }

        if (str_contains($raw, '#')) {
            [$raw] = explode('#', $raw, 2);
            $raw = trim((string)$raw);
        }
        if (preg_match('/^bb-account-(.+)$/i', $raw, $matches)) {
            $raw = trim((string)$matches[1]);
        }

        if (preg_match('/^\d+$/', $raw) === 1) {
            return $raw;
        }

        return '';
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

        Log::debug(sprintf(
            'BasisBank statement GET response for account "%s": %d bytes, has ViewState=%s (size %d), has GridView1=%s, has AccountDDL=%s',
            $statementId,
            strlen($html),
            str_contains($html, '__VIEWSTATE') ? 'yes' : 'no',
            strlen((string)(preg_match('/name="__VIEWSTATE"[^>]*value="([^"]*)"/', $html, $m) ? $m[1] : '')),
            str_contains($html, 'GridView1') ? 'yes' : 'no',
            str_contains($html, 'AccountDDL') ? 'yes' : 'no'
        ));
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

        Log::debug(sprintf('BasisBank statement POST URL for account "%s": %s (payload fields: %d, has Button2: %s)',
            $statementId,
            $postUrl,
            count($payload),
            isset($payload['ctl00$Content$Button2']) ? 'yes='.$payload['ctl00$Content$Button2'] : 'NO'
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
            Log::warning(
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
                Log::warning(
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
        // Detailed diagnostics to find why GridView1 is missing
        $hasGrid = str_contains($postedHtml, 'GridView1');
        $hasButton2 = str_contains($postedHtml, 'Button2');
        $hasAccountDDL = str_contains($postedHtml, 'AccountDDL');
        $hasLoginForm = $this->containsLoginForm($postedHtml);
        $titleMatch = preg_match('/<title[^>]*>([^<]*)<\/title>/i', $postedHtml, $titleMatches);
        $pageTitle = $titleMatch ? trim($titleMatches[1]) : '(no title)';
        // Extract first 500 chars of body text (strip tags) for debugging
        $bodyPreview = substr(strip_tags($postedHtml), 0, 500);
        $bodyPreview = preg_replace('/\s+/', ' ', $bodyPreview);
        Log::debug(sprintf(
            'BasisBank statement POST response for account "%s": HTTP %d, body %d bytes, has grid=%s, has Button2=%s, has AccountDDL=%s, isLoginForm=%s, title="%s"',
            $statementId,
            $status,
            strlen($postedHtml),
            $hasGrid ? 'yes' : 'no',
            $hasButton2 ? 'yes' : 'no',
            $hasAccountDDL ? 'yes' : 'no',
            $hasLoginForm ? 'yes' : 'no',
            $pageTitle
        ));
        Log::debug(sprintf('BasisBank statement POST body preview for account "%s": %s', $statementId, substr((string)$bodyPreview, 0, 300)));
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
        $xpath = new DOMXPath($document);
        $payload = [];

        $inputs = $xpath->query('//form//input[@name]');
        if (false !== $inputs) {
            foreach ($inputs as $input) {
                if (!$input instanceof DOMElement) {
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
                if (!$select instanceof DOMElement) {
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
                    if ($option instanceof DOMElement) {
                        $selectedValue = trim((string)$option->getAttribute('value'));
                    }
                }
                if ('' === $selectedValue) {
                    $options = $xpath->query('.//option', $select);
                    if (false !== $options && $options->length > 0) {
                        $option = $options->item(0);
                        if ($option instanceof DOMElement) {
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
        try {
            $start = null !== $dateFrom && '' !== trim($dateFrom)
                ? Carbon::parse($dateFrom)
                : Carbon::create(2000, 1, 1);
        } catch (\Exception) {
            Log::warning(sprintf('BasisBank statement: could not parse dateFrom "%s", using year 2000 fallback.', $dateFrom));
            $start = Carbon::create(2000, 1, 1);
        }
        try {
            $end = null !== $dateTo && '' !== trim($dateTo)
                ? Carbon::parse($dateTo)
                : Carbon::now();
        } catch (\Exception) {
            Log::warning(sprintf('BasisBank statement: could not parse dateTo "%s", using today.', $dateTo));
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
        // The previous code only set fields that existed in the HTML, which caused
        // date range fields to be missing for C/A (non-card) accounts.
        foreach ($defaults as $key => $value) {
            $payload[$key] = (string)$value;
        }

        Log::debug(sprintf(
            'BasisBank statement POST payload for account "%s": dateRange=%s..%s, viewstate=%s, accountDDL=%s, fields=%d',
            $statementId,
            $start->format('Y-m-d'),
            $end->format('Y-m-d'),
            isset($payload['__VIEWSTATE']) ? 'present' : 'missing',
            $payload['ctl00$Content$AccountDDL'] ?? '(not set)',
            count($payload)
        ));
        // Submit button: proven via browser DevTools capture (2026-03-26).
        // NAME: ctl00$Content$Button2  VALUE: Re-count  TYPE: submit  ID: Content_Button2
        // Without this field, ASP.NET does a no-op postback and returns an empty grid.
        $payload['ctl00$Content$Button2'] = 'Re-count';

        return $payload;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseStatementTransactionsFromHtml(
        string $html,
        string $statementId,
        ?string $dateFrom,
        ?string $dateTo,
        ?int $limit = null
    ): array {
        $document = new \DOMDocument();
        $loaded = @ $document->loadHTML($html);
        if (false === $loaded) {
            return [];
        }

        $fromTimestamp = $this->dateToTimestamp($dateFrom);
        $toTimestamp = $this->dateToTimestamp($dateTo);
        $expectedCurrency = $this->extractConfiguredCurrencyScope();
        $baseAccountId = $this->getBaseAccountId();
        $accountId = '' !== trim($baseAccountId) ? $baseAccountId : $statementId;

        $xpath = new DOMXPath($document);
        $rows = $xpath->query('//tr');
        if (false === $rows) {
            return [];
        }

        $parsed = [];
        $seen = [];
        foreach ($rows as $rowNode) {
            if (!$rowNode instanceof DOMElement) {
                continue;
            }
            $cells = $xpath->query('.//td', $rowNode);
            if (false === $cells || 0 === $cells->length) {
                continue;
            }
            $texts = [];
            foreach ($cells as $cell) {
                if (!$cell instanceof DOMElement) {
                    continue;
                }
                $text = $this->normalizeWhitespace((string)$cell->textContent);
                if ('' !== $text) {
                    $texts[] = $text;
                }
            }
            if ([] === $texts) {
                continue;
            }

            $date = $this->extractStatementDate($texts);
            if (null === $date) {
                continue;
            }
            $dateTimestamp = strtotime($date);
            if (false === $dateTimestamp) {
                continue;
            }
            if (null !== $fromTimestamp && $dateTimestamp < $fromTimestamp) {
                continue;
            }
            if (null !== $toTimestamp && $dateTimestamp > $toTimestamp) {
                continue;
            }

            $amount = $this->extractStatementAmount($texts, $expectedCurrency);
            if (null === $amount || abs($amount) < 0.000001) {
                continue;
            }

            $currency = $this->extractStatementCurrency($texts);
            if ('' === $currency) {
                $currency = $expectedCurrency;
            }

            $description = $this->extractStatementDescription($texts);
            if ('' === $description) {
                $description = sprintf('BasisBank statement transaction (%s)', $statementId);
            }

            $externalId = $this->extractStatementTransactionId($texts);
            if ('' === $externalId) {
                $externalId = md5((string)json_encode([$accountId, $date, $amount, $currency, $description]));
            }
            if (isset($seen[$externalId])) {
                continue;
            }
            $seen[$externalId] = true;

            $parsed[] = [
                'transactionId' => $externalId,
                'accountId'     => $accountId,
                'amount'        => (string)$amount,
                'currency'      => $currency,
                'date'          => $date,
                'description'   => $description,
                'merchant'      => $description,
            ];

            if (null !== $limit && count($parsed) >= $limit) {
                break;
            }
        }

        if ([] !== $parsed) {
            return $parsed;
        }

        $fallbackParsed = $this->parseStatementTransactionsFromAccountText(
            $html,
            $statementId,
            $fromTimestamp,
            $toTimestamp,
            $expectedCurrency,
            $accountId,
            $limit
        );
        if ([] !== $fallbackParsed) {
            Log::warning(
                sprintf(
                    'BasisBank statement text fallback parsed %d transaction row(s) for account "%s".',
                    count($fallbackParsed),
                    $statementId
                )
            );

            return $fallbackParsed;
        }

        return $parsed;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseStatementTransactionsFromAccountText(
        string $html,
        string $statementId,
        ?int $fromTimestamp,
        ?int $toTimestamp,
        string $expectedCurrency,
        string $accountId,
        ?int $limit = null
    ): array {
        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = $this->normalizeWhitespace(strip_tags($decoded));
        if ('' === $text) {
            return [];
        }

        $currencies = 'AED|ARS|AUD|AZN|BGN|BRL|BSD|CAD|CHF|CLP|CNY|COP|CRC|CZK|DKK|DOP|DZD|EGP|EUR|GBP|GEL|HKD|HRK|HUF|IDR|ILS|INR|JPY|KGS|KZT|MDL|MXN|NOK|PEN|PHP|PKR|PLN|RON|RSD|RUB|SEK|SGD|THB|TRY|UAH|USD|UZS|VND|ZAR';
        $pattern = '/((?:\d{2}[.\/-]\d{2}[.\/-]\d{4}|\d{4}[.\/-]\d{2}[.\/-]\d{2})(?:\s+\d{2}:\d{2}(?::\d{2})?)?)(.{0,240}?)([-+]?\d[\d\s,.()]{0,20})\s*(' . $currencies . '|₾|€|\\$)/u';
        $matches = [];
        preg_match_all($pattern, $text, $matches, PREG_SET_ORDER);
        if ([] === $matches) {
            return [];
        }

        $parsed = [];
        $seen = [];
        foreach ($matches as $index => $match) {
            $dateRaw = trim((string)($match[1] ?? ''));
            if ('' === $dateRaw) {
                continue;
            }
            $date = $this->normalizeDate($dateRaw);
            $dateTimestamp = strtotime($date);
            if (false === $dateTimestamp) {
                continue;
            }
            if (null !== $fromTimestamp && $dateTimestamp < $fromTimestamp) {
                continue;
            }
            if (null !== $toTimestamp && $dateTimestamp > $toTimestamp) {
                continue;
            }

            $currency = $this->normalizeStatementCurrencyToken((string)($match[4] ?? ''));
            if ('' === $currency) {
                continue;
            }
            if ('' !== $expectedCurrency && 0 !== strcasecmp($expectedCurrency, $currency)) {
                continue;
            }

            $amountRaw = (string)($match[3] ?? '');
            $amount = $this->parseAmountValue($amountRaw);

            $description = $this->normalizeWhitespace((string)($match[2] ?? ''));
            $description = trim($description, " \t\n\r\0\x0B-:|");
            if ('' === $description) {
                $description = sprintf('BasisBank statement transaction (%s)', $statementId);
            }

            $identity = md5((string)json_encode([
                $accountId,
                $date,
                number_format($amount, 2, '.', ''),
                $currency,
                $description,
            ]));
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;

            $externalId = strtoupper(substr($identity, 0, 24));
            $parsed[] = [
                'transactionId' => $externalId,
                'accountId'     => $accountId,
                'amount'        => (string)$amount,
                'currency'      => $currency,
                'date'          => $date,
                'description'   => $description,
                'merchant'      => $description,
            ];

            if (null !== $limit && count($parsed) >= $limit) {
                break;
            }
        }

        return $parsed;
    }

    private function extractStatementDate(array $texts): ?string
    {
        foreach ($texts as $text) {
            $match = [];
            if (preg_match('/\b(\d{4}[.\/-]\d{2}[.\/-]\d{2})\b/u', $text, $match) === 1) {
                return $this->normalizeDate((string)$match[1]);
            }
            if (preg_match('/\b(\d{2}[.\/-]\d{2}[.\/-]\d{4})\b/u', $text, $match) === 1) {
                return $this->normalizeDate((string)$match[1]);
            }
        }

        return null;
    }

    private function extractStatementAmount(array $texts, string $expectedCurrency): ?float
    {
        $fallback = null;
        foreach ($texts as $text) {
            $currency = $this->extractStatementCurrency([$text]);
            $matches = [];
            preg_match_all('/-?\d[\d\s,.()]+/u', $text, $matches);
            if (!isset($matches[0]) || [] === $matches[0]) {
                continue;
            }
            foreach ($matches[0] as $value) {
                $parsed = $this->parseAmountValue($value);
                if (abs($parsed) < 0.000001) {
                    continue;
                }
                if (null === $fallback) {
                    $fallback = $parsed;
                }
                if ('' !== $expectedCurrency && '' !== $currency && 0 !== strcasecmp($expectedCurrency, $currency)) {
                    continue;
                }

                return $parsed;
            }
        }

        return $fallback;
    }

    private function extractStatementCurrency(array $texts): string
    {
        foreach ($texts as $text) {
            $match = [];
            if (preg_match('/\b(AED|ARS|AUD|AZN|BGN|BRL|BSD|CAD|CHF|CLP|CNY|COP|CRC|CZK|DKK|DOP|DZD|EGP|EUR|GBP|GEL|HKD|HRK|HUF|IDR|ILS|INR|JPY|KGS|KZT|MDL|MXN|NOK|PEN|PHP|PKR|PLN|RON|RSD|RUB|SEK|SGD|THB|TRY|UAH|USD|UZS|VND|ZAR)\b/u', $text, $match) === 1) {
                return CurrencyCode::normalizeOrEmpty((string)$match[1]);
            }
        }

        return '';
    }

    private function normalizeStatementCurrencyToken(string $token): string
    {
        $currency = strtoupper(trim($token));
        if ('₾' === $currency) {
            $currency = 'GEL';
        }
        if ('€' === $currency) {
            $currency = 'EUR';
        }
        if ('$' === $currency) {
            $currency = 'USD';
        }

        return CurrencyCode::normalizeOrEmpty($currency);
    }

    private function extractStatementDescription(array $texts): string
    {
        $parts = [];
        foreach ($texts as $text) {
            if (preg_match('/^\d{2}[.\/-]\d{2}[.\/-]\d{4}$/u', $text) === 1) {
                continue;
            }
            if (preg_match('/^\d{4}[.\/-]\d{2}[.\/-]\d{2}$/u', $text) === 1) {
                continue;
            }
            if (preg_match('/^[A-Z]{3}$/u', strtoupper($text)) === 1) {
                continue;
            }
            if (preg_match('/^-?\d[\d\s,.()]+$/u', $text) === 1) {
                continue;
            }
            $parts[] = $text;
        }

        return trim(implode(' ', $parts));
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

    private function extractConfiguredCurrencyScope(): string
    {
        $raw = trim($this->accountId);
        if (!str_contains($raw, '#')) {
            return '';
        }
        [, $scope] = explode('#', $raw, 2);

        return CurrencyCode::normalizeOrEmpty((string)$scope);
    }

    private function dateToTimestamp(?string $value): ?int
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }
        try {
            return Carbon::parse($value)->startOfDay()->timestamp;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function normalizeWhitespace(string $value): string
    {
        return preg_replace('/\s+/u', ' ', trim($value)) ?: '';
    }

    /**
     * @param array<int, array<string, mixed>> $base
     * @param array<int, array<string, mixed>> $extra
     * @return array<int, array<string, mixed>>
     */
    private function mergeUniqueRows(array $base, array $extra): array
    {
        $result = $base;
        $seen = [];
        foreach ($base as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = $this->rowIdentity($row);
            $seen[$key] = true;
        }
        foreach ($extra as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = $this->rowIdentity($row);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $row;
        }

        return $result;
    }

    private function rowIdentity(array $row): string
    {
        $id = trim((string)($row['TransactionID'] ?? $row['transactionId'] ?? $row['TransactionReference'] ?? $row['TransferID'] ?? ''));
        if ('' !== $id) {
            return 'id:'.$id;
        }

        return 'row:'.md5((string)json_encode($row));
    }

    /**
     * @throws ImporterHttpException
     */
    private function callCardModuleWithRetry(string $funq, array $form): array
    {
        $attempt = 1;
        $sessionRecoveryAttempt = 0;
        while (true) {
            try {
                return $this->callCardModule($funq, $form);
            } catch (ImporterHttpException $e) {
                if ($this->isSessionRecoveryCandidate($e) && $sessionRecoveryAttempt < self::MAX_SESSION_RECOVERY_ATTEMPTS) {
                    $sessionRecoveryAttempt++;
                    $this->recoverWebSessionForCardModule($funq, $e);

                    continue;
                }
                if (false === $this->isRetryableCardModuleFailure($e) || $attempt >= self::MAX_RETRY_ATTEMPTS) {
                    throw $e;
                }
                $delayMs = self::BASE_RETRY_DELAY_MS * $attempt;
                Log::warning(
                    sprintf(
                        'BasisBank CardModule transient error for "%s" (attempt %d/%d, status %d): %s. Retrying in %dms.',
                        $funq,
                        $attempt,
                        self::MAX_RETRY_ATTEMPTS,
                        (int)$e->statusCode,
                        $e->getMessage(),
                        $delayMs
                    )
                );
                usleep($delayMs * 1000);
                $attempt++;
            }
        }
    }

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
     * @throws ImporterHttpException
     */
    private function recoverWebSessionForCardModule(string $funq, ImporterHttpException $trigger): void
    {
        $login = trim(SecretManager::getLogin());
        $password = trim(SecretManager::getPassword());
        if ('' === $login || '' === $password) {
            throw $trigger;
        }

        Log::warning(
            sprintf(
                'BasisBank CardModule auth recovery triggered for "%s" after status %d: %s',
                $funq,
                (int)$trigger->statusCode,
                $trigger->getMessage()
            )
        );

        try {
            $client = new BasisBankWebAuthClient();
            $state = $client->start(
                $login,
                $password,
                SecretManager::getRequestSmsCode(),
                SecretManager::getTrustDevice(),
                $this->resolveSessionArtifact()
            );
        } catch (ImporterErrorException $e) {
            $httpException = new ImporterHttpException(sprintf('BasisBank session recovery failed for "%s": %s', $funq, $e->getMessage()), 0, $e);
            $httpException->statusCode = (int)$trigger->statusCode;
            throw $httpException;
        }

        $authState = '' !== trim($state->getAuthState()) ? $state->getAuthState() : $state->getStatus();
        SecretManager::saveAuthState($authState);
        SecretManager::saveSessionArtifact($state->getSessionArtifact());

        if (!$state->isAuthenticated()) {
            $message = trim($state->getErrorMessage());
            if ('' === $message) {
                $message = sprintf(
                    'BasisBank authentication requires additional user action (%s).',
                    strtolower($state->getStatus())
                );
            }
            $httpException = new ImporterHttpException(
                sprintf(
                    'BasisBank session recovery requires re-authentication for "%s": %s',
                    $funq,
                    $message
                )
            );
            $httpException->statusCode = (int)$trigger->statusCode;
            throw $httpException;
        }

        $this->sessionCookies = $this->decodeArtifact($state->getSessionArtifact());
        $this->sessionCookiesLoaded = true;

        Log::warning(sprintf('BasisBank CardModule auth recovery succeeded for "%s"; retrying request.', $funq));
    }

    private function isRetryableCardModuleFailure(ImporterHttpException $e): bool
    {
        $statusCode = (int)$e->statusCode;
        if (in_array($statusCode, [429, 500, 502, 503, 504, 520, 522, 524], true)) {
            return true;
        }

        $message = strtolower($e->getMessage());

        return str_contains($message, 'timeout')
            || str_contains($message, 'gateway')
            || str_contains($message, 'rate limit')
            || str_contains($message, 'temporarily unavailable');
    }

    private function callCardModule(string $funq, array $form): array
    {
        $cookies = $this->getSessionCookies();
        if ([] === $cookies) {
            throw new ImporterHttpException('BasisBank web session is not available for transaction retrieval.');
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
                    'form_params'    => $form,
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
            Log::warning(
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
            Log::warning(sprintf('BasisBank CardModule redirect for "%s" was followed successfully (HTTP %d).', $funq, $redirectStatus));

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

    private function emitProgress(array $payload): void
    {
        if (!is_callable($this->progressReporter)) {
            return;
        }
        try {
            call_user_func($this->progressReporter, $payload);
        } catch (\Throwable $e) {
            Log::debug(sprintf('BasisBank progress reporter callback failed: %s', $e->getMessage()));
        }
    }

    private function formatDateOrNull(?string $date): ?string
    {
        if (null === $date || '' === trim($date)) {
            return null;
        }

        try {
            return Carbon::parse($date)->format(self::DATE_FORMAT);
        } catch (\Exception $e) {
            return null;
        }
    }

    private function extractRowsFromPayload(array $payload): array
    {
        if (array_is_list($payload)) {
            return $payload;
        }

        foreach (['d', 'data', 'Data', 'result', 'Result', 'transactions', 'Transactions', 'items', 'Items', 'rows', 'Rows'] as $key) {
            if (!isset($payload[$key])) {
                continue;
            }

            $nested = $this->coercePayload($payload[$key]);
            if (is_array($nested) && array_is_list($nested)) {
                return $nested;
            }
            if (!is_array($nested)) {
                continue;
            }
            foreach (['items', 'Items', 'rows', 'Rows', 'transactions', 'Transactions'] as $nestedKey) {
                if (!isset($nested[$nestedKey])) {
                    continue;
                }
                $deeper = $this->coercePayload($nested[$nestedKey]);
                if (is_array($deeper) && array_is_list($deeper)) {
                    return $deeper;
                }
            }
        }

        return [];
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

    private function headers(): array
    {
        $headers = [];

        if ('' !== $this->consentId) {
            $headers['Consent-ID'] = $this->consentId;
        }

        $psuIp = trim((string)config('basisbank.psu_ip_address', ''));
        if ('' !== $psuIp) {
            $headers['PSU-IP-Address'] = $psuIp;
        }

        $psuId = trim((string)config('basisbank.psu_id', ''));
        if ('' !== $psuId) {
            $headers['PSU-ID'] = $psuId;
        }

        return $headers;
    }

    private function extractRows(array $payload): array
    {
        $transactions = $payload['transactions'] ?? $payload;
        $rows         = [];

        if (isset($transactions['booked']) && is_array($transactions['booked'])) {
            $rows = array_merge($rows, $transactions['booked']);
        }
        if (isset($transactions['pending']) && is_array($transactions['pending'])) {
            $rows = array_merge($rows, $transactions['pending']);
        }
        if ([] === $rows && is_array($transactions) && array_is_list($transactions)) {
            $rows = $transactions;
        }

        return $rows;
    }

    private function normalizeTransaction(array $row): array
    {
        if ($this->isWebRow($row)) {
            return $this->normalizeWebTransaction($row);
        }

        $amountRaw     = $row['transactionAmount']['amount'] ?? $row['amount']['amount'] ?? $row['amount'] ?? '0';
        $amount        = (float)$amountRaw;
        $indicator     = strtoupper((string)($row['creditDebitIndicator'] ?? $row['debitCreditIndicator'] ?? ''));
        if (str_contains($indicator, 'DBIT') || str_contains($indicator, 'DEBIT')) {
            $amount = -1 * abs($amount);
        }
        if (str_contains($indicator, 'CRDT') || str_contains($indicator, 'CREDIT')) {
            $amount = abs($amount);
        }

        // Use normalizeOrEmpty so unknown currency passes through the filter and inherits
        // from the account, rather than silently defaulting to EUR (which causes GEL accounts
        // to drop transactions when the filter sees EUR !== GEL).
        $currency      = CurrencyCode::normalizeOrEmpty((string)($row['transactionAmount']['currency'] ?? $row['amount']['currency'] ?? $row['currency'] ?? ''));
        $date          = (string)($row['bookingDateTime'] ?? $row['bookingDate'] ?? $row['valueDate'] ?? $row['transactionDate'] ?? $row['date'] ?? date('Y-m-d'));
        $description   = '';
        if (isset($row['remittanceInformationUnstructuredArray']) && is_array($row['remittanceInformationUnstructuredArray'])) {
            $description = trim(implode(' ', $row['remittanceInformationUnstructuredArray']));
        }
        if ('' === $description) {
            $description = trim((string)($row['remittanceInformationUnstructured'] ?? $row['additionalInformation'] ?? $row['description'] ?? ''));
        }

        $merchant      = trim((string)($row['creditorName'] ?? $row['debtorName'] ?? $row['counterpartyName'] ?? ''));
        $externalIdRaw = (string)($row['transactionId'] ?? $row['entryReference'] ?? $row['internalTransactionId'] ?? '');
        if ('' === trim($externalIdRaw)) {
            // Use base account ID (without #currency suffix) for stable hashes across imports.
            $externalIdRaw = md5((string)json_encode([$this->getBaseAccountId(), $amount, $date, $description]));
        }

        return [
            'id'          => $externalIdRaw,
            'accountId'   => $this->accountId,
            'amount'      => (string)$amount,
            'currency'    => $currency,
            'date'        => $date,
            'merchant'    => $merchant,
            'description' => $description,
        ];
    }

    private function normalizeWebTransaction(array $row): array
    {
        $accountIban = trim((string)($row['AccountIban'] ?? ''));
        $mainAccountId = trim((string)($row['MainAccountID'] ?? ''));
        $accountId   = '' !== $accountIban ? $accountIban : ('' !== $mainAccountId ? $mainAccountId : $this->accountId);

        $amountRaw = (float)$this->parseAmountValue($row['Amount'] ?? 0);
        $indicator = strtoupper((string)($row['CreditDebitIndicator'] ?? ''));
        if (str_contains($indicator, 'DBIT') || str_contains($indicator, 'DEBIT')) {
            $amountRaw = -1 * abs($amountRaw);
        }
        if (str_contains($indicator, 'CRDT') || str_contains($indicator, 'CREDIT')) {
            $amountRaw = abs($amountRaw);
        }

        // Use normalizeOrEmpty: when Ccy is absent in CardModule JSON, the transaction should
        // inherit the account's currency rather than defaulting to EUR.
        $currency   = CurrencyCode::normalizeOrEmpty((string)($row['Ccy'] ?? ''));
        $date       = $this->normalizeDate((string)($row['DocDate'] ?? $row['DateTime'] ?? $row['Date'] ?? date('Y-m-d')));
        $description = trim((string)($row['Description'] ?? ''));
        $merchant   = trim((string)($row['Description'] ?? $row['CardPan'] ?? ''));
        $externalId = (string)($row['TransactionID'] ?? $row['TransactionReference'] ?? $row['TransferID'] ?? '');
        if ('' === trim($externalId)) {
            // Use base account ID (without #currency suffix) for stable hashes across imports.
            $baseAccountId = str_contains($accountId, '#') ? substr($accountId, 0, (int)strpos($accountId, '#')) : $accountId;
            $externalId = md5((string)json_encode([$baseAccountId, $amountRaw, $date, $description]));
        }

        return [
            'id'          => $externalId,
            'accountId'   => $accountId,
            'amount'      => (string)$amountRaw,
            'currency'    => $currency,
            'date'        => $date,
            'merchant'    => $merchant,
            'description' => $description,
        ];
    }

    private function isWebRow(array $row): bool
    {
        return isset($row['TransactionID'])
            || isset($row['AccountIban'])
            || isset($row['DocDate'])
            || isset($row['Ccy'])
            || isset($row['TransferID']);
    }

    private function matchesConfiguredAccount(array $row): bool
    {
        $configuredId = $this->getBaseAccountId();
        if ('' === trim($configuredId)) {
            return true;
        }

        $configured = trim($configuredId);
        $normalizedConfigured = strtoupper(str_replace(' ', '', $configured));
        $configuredVariants = [$normalizedConfigured];
        if (preg_match('/^BB-(?:CARD|ACCOUNT)-(.+)$/i', $normalizedConfigured, $matches)) {
            $suffix = trim((string)$matches[1]);
            if ('' !== $suffix) {
                $configuredVariants[] = $suffix;
            }
        }
        $candidates = [
            trim((string)($row['AccountIban'] ?? '')),
            trim((string)($row['MainAccountID'] ?? '')),
            trim((string)($row['AccountIbanEncrypted'] ?? '')),
            trim((string)($row['accountId'] ?? '')),
            trim((string)($row['sourceAccountId'] ?? '')),
        ];

        foreach ($candidates as $candidate) {
            if ('' === $candidate) {
                continue;
            }
            if (0 === strcasecmp($candidate, $configured)) {
                return true;
            }
            $normalizedCandidate = strtoupper(str_replace(' ', '', $candidate));
            if ('' === $normalizedCandidate) {
                continue;
            }
            foreach ($configuredVariants as $variant) {
                if (0 === strcasecmp($normalizedCandidate, $variant)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function getBaseAccountId(): string
    {
        $raw = trim($this->accountId);
        if ('' === $raw) {
            return '';
        }
        if (!str_contains($raw, '#')) {
            return $raw;
        }

        [$base] = explode('#', $raw, 2);

        return trim((string)$base);
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

    private function normalizeDate(string $value): string
    {
        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Exception $e) {
            return date('Y-m-d');
        }
    }

    private function containsLoginForm(string $html): bool
    {
        return str_contains($html, 'id="UTXT"') && str_contains($html, 'id="PTXT"');
    }

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
}
