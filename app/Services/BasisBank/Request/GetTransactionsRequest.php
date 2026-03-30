<?php

declare(strict_types=1);

namespace App\Services\BasisBank\Request;

use App\Exceptions\ImporterErrorException;
use App\Exceptions\ImporterHttpException;
// Cross-provider shared response class; lives under LunchFlow namespace but used by BasisBank, TBank, and TRC20 as well.
use App\Services\LunchFlow\Response\GetTransactionsResponse;
use App\Services\Shared\Request\BearerJsonRequest;
use App\Services\Shared\Support\CurrencyCode;
use Carbon\Carbon;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Log;

class GetTransactionsRequest extends BearerJsonRequest
{
    use BasisBankWebSessionTrait;

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

    public static function clearCache(): void
    {
        self::$webSessionRowsCache = [];
    }

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
        return CurrencyCode::selectDominant($this->sampleAccountCurrencies($dateFrom, $dateTo, $sampleSize));
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
        $historyYears = (int) config('basisbank.statement_history_years', 25);
        $startDate = $this->formatDateOrNull($dateFrom) ?? Carbon::now()->subYears($historyYears)->format(self::DATE_FORMAT);
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

                $pageRows = $this->extractArrayPayload($payload);
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

}
