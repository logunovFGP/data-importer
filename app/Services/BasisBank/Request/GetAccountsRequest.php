<?php

declare(strict_types=1);

namespace App\Services\BasisBank\Request;

use App\Exceptions\ImporterErrorException;
use App\Exceptions\ImporterHttpException;
use App\Services\BasisBank\Auth\BasisBankWebAuthClient;
use App\Services\BasisBank\Authentication\SecretManager;
use App\Services\LunchFlow\Response\GetAccountsResponse;
use App\Services\Shared\Support\CurrencyCode;
use Carbon\Carbon;
use App\Services\Shared\Request\BearerJsonRequest;
use DOMElement;
use DOMXPath;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use Psr\Http\Message\ResponseInterface;

class GetAccountsRequest extends BearerJsonRequest
{
    private const string BASE_WEB_URL = 'https://www.bankonline.ge';
    private const string BALANCE_PAGE_PATH = '/Balance.aspx';
    private const string INFO_PAGE_PATH = '/Info.aspx';
    private const string CARD_MODULE_PATH = '/Handlers/CardModule.ashx';
    private const string STATEMENT_PAGE_PATH = '/Accounts/Statement/Statement.aspx';
    private const float TIMEOUT_SECONDS = 3.14;
    private const int DEFAULT_MAX_PAGES = 120;
    private const int DEFAULT_PAGE_SIZE = 20;
    private const int MAX_SESSION_RECOVERY_ATTEMPTS = 1;
    private const int TRANSACTION_DISCOVERY_LOOKBACK_YEARS = 2;
    private const string DATE_FORMAT = 'd/m/Y';
    private const string SYNC_IDS_KEY = 'sync_ids';

    private float $timeOut = self::TIMEOUT_SECONDS;
    private array $sessionCookies = [];
    private bool $sessionCookiesLoaded = false;

    public function __construct(
        private readonly string $apiToken,
        private readonly string $consentId = '',
        private readonly string $sessionArtifact = ''
    ) {
        parent::__construct((string)config('basisbank.api_url'), $apiToken);
    }

    public function setTimeOut(float $timeOut): void
    {
        $this->timeOut = $timeOut;
        parent::setTimeOut($timeOut);
    }

    /**
     * @throws ImporterHttpException
     */
    public function get(): GetAccountsResponse
    {
        if (true === config('importer.fake_data')) {
            return new GetAccountsResponse(
                [
                    [
                        'id'               => 'GE00BASIS000000000001',
                        'name'             => 'BasisBank Demo Account',
                        'institution_name' => 'BasisBank',
                        'institution_logo' => '',
                        'provider'         => 'basisbank',
                        'currency'         => 'GEL',
                        'status'           => 'active',
                    ],
                ]
            );
        }

        if ('' !== trim($this->resolveSessionArtifact())) {
            $normalized = $this->getFromWebSession();

            return new GetAccountsResponse($normalized);
        }

        if (
            '' === trim($this->sessionArtifact)
            && '' === trim($this->apiToken)
            && '' === trim($this->consentId)
        ) {
            throw new ImporterHttpException('BasisBank requires either a valid web session artifact or API token/consent-id for account retrieval.');
        }

        $rows = $this->extractRows(
            $this->getJson((string)config('basisbank.accounts_endpoint'), $this->headers())
        );

        $normalized = [];
        foreach ($rows as $row) {
            $account = $this->normalizeAccount($row);
            if (null !== $account) {
                $normalized[] = $account;
            }
        }

        return new GetAccountsResponse($normalized);
    }

    private function getFromWebSession(): array
    {
        $cookies = $this->getSessionCookies();
        if ([] === $cookies) {
            throw new ImporterHttpException('BasisBank web-session artifacts are missing for account collection.');
        }

        $html = $this->requestBalancePageWithSessionRecovery();
        $accounts = $this->parseBalanceAccountsFromHtml($html);
        $cardRows = [];
        try {
            $cardRows = $this->getCardAccountsFromWebSession();
        } catch (ImporterHttpException $e) {
            if ($this->isSessionRecoveryCandidate($e)) {
                throw $e;
            }
            // Best-effort enrichment: keep balance-page accounts even if card module is unavailable.
        }
        if ([] !== $cardRows) {
            $accounts = $this->mergeAccountRows($accounts, $cardRows);
        }
        $accounts = $this->enrichNonCardAccountsWithAmountHints($accounts);
        if ([] !== $cardRows) {
            // Re-merge after enrichment: non-card rows may gain IBAN/sync IDs from statement mapping.
            $accounts = $this->mergeAccountRows($accounts, $cardRows);
        }
        $transactionRows = [];
        try {
            $transactionRows = $this->getTransactionRowsFromWebSession($accounts);
            if ([] !== $transactionRows) {
                $accounts = $this->ensureAccountsForTransactions($accounts, $transactionRows);
                $accounts = $this->splitAccountsByCurrency($accounts, $transactionRows);
            }
        } catch (ImporterHttpException $e) {
            if ($this->isSessionRecoveryCandidate($e)) {
                throw $e;
            }
            // Best-effort enrichment: transaction discovery should not block account mapping.
        }

        if ([] === $transactionRows) {
            $accounts = $this->splitAccountsByCurrency($accounts, []);
        }

        return $accounts;
    }

    /**
     * @throws ImporterHttpException
     */
    private function requestBalancePageWithSessionRecovery(): string
    {
        $attempt = 0;
        while (true) {
            try {
                return $this->requestBalancePage($this->getSessionCookies());
            } catch (ImporterHttpException $e) {
                if (!$this->isSessionRecoveryCandidate($e) || $attempt >= self::MAX_SESSION_RECOVERY_ATTEMPTS) {
                    throw $e;
                }
                $attempt++;
                $this->recoverWebSessionForCardModule('balance-page', $e);
            }
        }
    }

    private function getCardAccountsFromWebSession(): array
    {
        $payload = $this->callCardModuleWithSessionRecovery('getcardlist', []);
        if ([] === $payload) {
            return [];
        }

        $rows = $this->extractArrayPayload($payload);
        $accounts = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $mapped = $this->normalizeCardAccount($row);
            if (null !== $mapped) {
                $accounts[] = $mapped;
            }
        }

        return $accounts;
    }

    private function parseBalanceAccountsFromHtml(string $html): array
    {
        $document = new \DOMDocument();
        $loaded = @ $document->loadHTML($html);
        if (false === $loaded) {
            return [];
        }

        $xpath = new DOMXPath($document);
        $anchors = $xpath->query('//a[contains(@href, "/Accounts/Statement/Statement.aspx?ID=")]');
        if (false === $anchors) {
            return [];
        }

        $rows = [];
        foreach ($anchors as $anchor) {
            if (!$anchor instanceof DOMElement) {
                continue;
            }

            $href = (string)$anchor->getAttribute('href');
            if (!preg_match('/[?&]ID=(\d+)/i', $href, $match)) {
                continue;
            }

            $statementId = $match[1];
            $rawTitle = $this->normalizeWhitespace((string)$anchor->textContent);
            $rowNode = $anchor;
            while (null !== $rowNode->parentNode && $rowNode->parentNode instanceof DOMElement) {
                $rowNode = $rowNode->parentNode;
                if ('tr' === strtolower($rowNode->tagName)) {
                    break;
                }
            }
            $rowTextParts = [];
            if ('tr' === strtolower($rowNode->tagName)) {
                $cells = $xpath->query('.//td', $rowNode);
                if (false !== $cells) {
                    foreach ($cells as $cell) {
                        if (!$cell instanceof DOMElement) {
                            continue;
                        }
                        $text = $this->normalizeWhitespace((string)$cell->textContent);
                        if ('' !== $text) {
                            $rowTextParts[] = $text;
                        }
                    }
                }
            }
            if ([] === $rowTextParts && null !== $anchor->parentNode && $anchor->parentNode instanceof DOMElement) {
                $fallbackRowText = $this->normalizeWhitespace((string)$anchor->parentNode->textContent);
                if ('' !== $fallbackRowText) {
                    $rowTextParts[] = $fallbackRowText;
                }
            }
            $combinedText = trim($rawTitle . ' ' . implode(' ', $rowTextParts));
            $iban = $this->extractIban($combinedText);
            $name = '' !== $rawTitle ? $rawTitle : sprintf('BasisBank account %s', $statementId);
            $amounts = $this->parseAmounts($rowTextParts !== [] ? $rowTextParts : $combinedText);
            $currency = CurrencyCode::normalizeOrEmpty($this->extractCurrency($combinedText) ?? '');
            $isCard = preg_match('/\b(card|mastercard|visa)\b/i', $combinedText) === 1;
            $balance = null;
            $available = null;
            if ([] !== $amounts) {
                $balance = $amounts[count($amounts) - 1];
                $available = $amounts[count($amounts) - 2] ?? $balance;
            }

            $rows[] = [
                'id'               => '' !== $iban ? $iban : (string)$statementId,
                'name'             => $name,
                'institution_name' => 'BasisBank',
                'institution_logo' => '',
                'provider'         => 'basisbank',
                'iban'             => $iban,
                'bban'             => (string)$statementId,
                'currency'         => $currency,
                'status'           => 'active',
                'is_card'          => $isCard,
                'balance'          => $balance,
                'available'        => $available,
                'extra'            => [
                    'IBAN'              => $iban,
                    'BBAN'              => (string)$statementId,
                    'Currency'          => $currency,
                    'Balance'           => $balance,
                    'Available balance' => $available,
                    'Card account'      => $isCard ? 'yes' : 'no',
                ],
                self::SYNC_IDS_KEY => $this->uniqueSyncIds(
                    [
                        '' !== $iban ? $iban : null,
                        (string)$statementId,
                        sprintf('bb-account-%s', (string)$statementId),
                    ]
                ),
            ];
        }

        foreach ($this->extractStatementIdsFromHtml($html) as $statementId) {
            $syntheticId = (string)$statementId;
            $exists = false;
            foreach ($rows as $existingRow) {
                if (!is_array($existingRow)) {
                    continue;
                }
                if ((string)($existingRow['id'] ?? '') === $syntheticId) {
                    $exists = true;
                    break;
                }
            }
            if ($exists) {
                continue;
            }
            $rows[] = [
                'id'               => $syntheticId,
                'name'             => sprintf('BasisBank account %s', $statementId),
                'institution_name' => 'BasisBank',
                'institution_logo' => '',
                'provider'         => 'basisbank',
                'iban'             => '',
                'bban'             => $syntheticId,
                'currency'         => '',
                'status'           => 'active',
                'is_card'          => false,
                'balance'          => null,
                'available'        => null,
                'extra'            => [
                    'IBAN'              => '',
                    'BBAN'              => $syntheticId,
                    'Currency'          => '',
                    'Balance'           => null,
                    'Available balance' => null,
                    'Card account'      => 'no',
                ],
                self::SYNC_IDS_KEY => $this->uniqueSyncIds([$syntheticId, sprintf('bb-account-%s', $statementId)]),
            ];
        }

        $statementIds = $this->extractStatementIdsFromHtml($html);
        $statementAccountMap = $this->extractStatementAccountMapFromHtmlOptions($html, $statementIds);
        if ([] === $statementAccountMap) {
            $statementAccountMap = $this->extractStatementViewStateAccountMap($html, $statementIds);
        }
        if ([] !== $statementAccountMap) {
            $rows = $this->applyStatementAccountMapToRows($rows, $statementAccountMap);
            logger()->debug(sprintf('BasisBank balance viewstate account-map applied to %d account candidate(s).', count($statementAccountMap)));
        }

        $dedup = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['id'])) {
                continue;
            }
            $id = (string)$row['id'];
            if (!isset($dedup[$id]) || !is_array($dedup[$id])) {
                $dedup[$id] = $row;

                continue;
            }
            $dedup[$id] = $this->mergeDuplicateBalanceRows($dedup[$id], $row);
        }

        return array_values($dedup);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, array<string, string>> $statementAccountMap
     * @return array<int, array<string, mixed>>
     */
    private function applyStatementAccountMapToRows(array $rows, array $statementAccountMap): array
    {
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $statementId = trim((string)($row['bban'] ?? ''));
            if ('' === $statementId) {
                $candidateId = trim((string)($row['id'] ?? ''));
                if (preg_match('/^\d+$/', $candidateId) === 1) {
                    $statementId = $candidateId;
                }
            }
            if ('' === $statementId || !isset($statementAccountMap[$statementId]) || !is_array($statementAccountMap[$statementId])) {
                continue;
            }

            $mapped = $statementAccountMap[$statementId];
            $mappedCurrency = CurrencyCode::normalizeOrEmpty((string)($mapped['currency'] ?? ''));
            $mappedIban = trim((string)($mapped['iban'] ?? ''));
            $mappedKind = strtolower(trim((string)($mapped['kind'] ?? '')));
            if ('card' !== $mappedKind) {
                $mappedKind = 'account';
            }

            if ('' !== $mappedCurrency) {
                $row['currency'] = $mappedCurrency;
            }
            if ('' !== $mappedIban) {
                $row['iban'] = $mappedIban;
            }
            if ('card' === $mappedKind) {
                $row['is_card'] = true;
            }

            $extra = is_array($row['extra'] ?? null) ? $row['extra'] : [];
            if ('' !== $mappedCurrency) {
                $extra['Currency'] = $mappedCurrency;
            }
            if ('' !== $mappedIban) {
                $extra['IBAN'] = $mappedIban;
            }
            if ('' !== $statementId) {
                $extra['BBAN'] = $statementId;
            }
            if ('card' === $mappedKind) {
                $extra['Card account'] = 'yes';
            }
            if ([] !== $extra) {
                $row['extra'] = $extra;
            }

            $syncIds = $this->getAccountSyncIds($row);
            if ('' !== $statementId) {
                $syncIds[] = sprintf('bb-account-%s', $statementId);
                $syncIds[] = sprintf('basisbank:%s:%s', $mappedKind, $statementId);
                if ('' !== $mappedCurrency) {
                    $syncIds[] = sprintf('basisbank:%s:%s:%s', $mappedKind, $statementId, $mappedCurrency);
                }
            }
            if ('' !== $mappedIban) {
                $syncIds[] = $mappedIban;
            }
            $row[self::SYNC_IDS_KEY] = $this->uniqueSyncIds($syncIds);

            logger()->debug(
                sprintf(
                    'BasisBank balance viewstate mapping resolved account "%s": currency="%s", iban="%s", kind="%s".',
                    $statementId,
                    $mappedCurrency,
                    $mappedIban,
                    $mappedKind
                )
            );

            $rows[$index] = $row;
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $candidate
     * @return array<string, mixed>
     */
    private function mergeDuplicateBalanceRows(array $existing, array $candidate): array
    {
        $existingIsCard = (bool)($existing['is_card'] ?? false);
        $candidateIsCard = (bool)($candidate['is_card'] ?? false);

        $primary = $existing;
        $secondary = $candidate;
        if ($candidateIsCard && !$existingIsCard) {
            $primary = $candidate;
            $secondary = $existing;
        }

        foreach (['name', 'institution_name', 'institution_logo', 'provider', 'iban', 'bban', 'currency', 'status'] as $field) {
            if (
                '' === trim((string)($primary[$field] ?? ''))
                && '' !== trim((string)($secondary[$field] ?? ''))
            ) {
                $primary[$field] = $secondary[$field];
            }
        }
        foreach (['balance', 'available'] as $field) {
            if (
                null === ($primary[$field] ?? null)
                && null !== ($secondary[$field] ?? null)
            ) {
                $primary[$field] = $secondary[$field];
            }
        }

        $primaryExtra = is_array($primary['extra'] ?? null) ? $primary['extra'] : [];
        $secondaryExtra = is_array($secondary['extra'] ?? null) ? $secondary['extra'] : [];
        if ([] !== $secondaryExtra) {
            $primary['extra'] = array_merge($secondaryExtra, $primaryExtra);
        }

        $primary[self::SYNC_IDS_KEY] = $this->uniqueSyncIds(array_merge($this->getAccountSyncIds($primary), $this->getAccountSyncIds($secondary)));
        $primary['is_card'] = (bool)($primary['is_card'] ?? false) || (bool)($secondary['is_card'] ?? false);

        return $primary;
    }

    private function extractStatementIdsFromHtml(string $html): array
    {
        $matches = [];
        preg_match_all('/Accounts\/Statement\/Statement\.aspx\?ID=(\d+)/i', $html, $matches);
        if (!isset($matches[1]) || [] === $matches[1]) {
            return [];
        }

        $statementIds = [];
        foreach ($matches[1] as $statementId) {
            $normalized = trim((string)$statementId);
            if ('' === $normalized) {
                continue;
            }
            $statementIds[$normalized] = true;
        }

        return array_keys($statementIds);
    }

    private function normalizeCardAccount(array $row): ?array
    {
        $accountIban = trim((string)($row['AccountIban'] ?? ''));
        $encryptedIban = trim((string)($row['AccountIbanEncrypted'] ?? ''));
        $mainAccountId = trim((string)($row['MainAccountID'] ?? ''));
        $iban = '' !== $accountIban ? $accountIban : ('' !== $encryptedIban ? $encryptedIban : '');
        if ('' === $iban && '' === $mainAccountId) {
            return null;
        }
        if ('' === $iban) {
            $iban = sprintf('bb-card-%s', $mainAccountId);
        }

        $name = trim((string)($row['AccountName'] ?? $row['ProductName'] ?? $row['AccountDescription'] ?? $iban));
        $currency = CurrencyCode::normalizeOrEmpty((string)(
            $row['MainCCy'] ?? $this->firstArrayString($row['CcyArray'] ?? null) ?? ''
        ));
        $balance = $this->parseAmountValue($row['Amount'] ?? null);

        return [
            'id'               => $iban,
            'name'             => $name,
            'institution_name' => 'BasisBank',
            'institution_logo' => '',
            'provider'         => 'basisbank',
            'iban'             => '' !== $accountIban ? $accountIban : '',
            'bban'             => $mainAccountId,
            'currency'         => $currency,
            'status'           => 'active',
            'is_card'          => true,
            'balance'          => $balance,
            'available'        => $balance,
            'extra'            => [
                'IBAN'              => '' !== $accountIban ? $accountIban : '',
                'BBAN'              => $mainAccountId,
                'Currency'          => $currency,
                'Balance'           => $balance,
                'Available balance' => $balance,
                'Card account'      => 'yes',
            ],
            self::SYNC_IDS_KEY => $this->uniqueSyncIds(
                [
                    $iban,
                    '' !== $accountIban ? $accountIban : null,
                    '' !== $encryptedIban ? $encryptedIban : null,
                    '' !== $mainAccountId ? $mainAccountId : null,
                ]
            ),
        ];
    }

    private function mergeAccountRows(array $balanceAccounts, array $cardRows): array
    {
        $merged = [];
        $identityIndex = [];
        foreach ($balanceAccounts as $account) {
            if (!is_array($account) || !isset($account['id'])) {
                continue;
            }
            $merged[] = $account;
            $rowIndex = count($merged) - 1;
            foreach ($this->getAccountSyncIds($account) as $syncId) {
                $identityIndex[$this->normalizeAccountKey($syncId)] = $rowIndex;
            }
        }

        foreach ($cardRows as $cardRow) {
            if (!is_array($cardRow) || !isset($cardRow['id'])) {
                continue;
            }
            $syncIds = $this->getAccountSyncIds($cardRow);
            $existingIndex = null;
            foreach ($syncIds as $syncId) {
                $normalized = $this->normalizeAccountKey($syncId);
                if (isset($identityIndex[$normalized])) {
                    $existingIndex = (int)$identityIndex[$normalized];
                    break;
                }
            }

            if (null === $existingIndex) {
                $merged[] = $cardRow;
                $newIndex = count($merged) - 1;
                foreach ($syncIds as $syncId) {
                    $identityIndex[$this->normalizeAccountKey($syncId)] = $newIndex;
                }

                continue;
            }

            $existing = $merged[$existingIndex];
            $existing[self::SYNC_IDS_KEY] = $this->uniqueSyncIds(array_merge($this->getAccountSyncIds($existing), $syncIds));
            $existingId = trim((string)($existing['id'] ?? ''));
            $cardId = trim((string)($cardRow['id'] ?? ''));
            if ('' !== $cardId && $cardId !== $existingId) {
                if ('' !== $existingId) {
                    $syncIds[] = $existingId;
                }
                $existing['id'] = $cardId;
            }
            if ('' !== trim((string)($cardRow['name'] ?? ''))) {
                $existing['name'] = $cardRow['name'];
            }
            if (null !== ($cardRow['balance'] ?? null)) {
                $existing['balance'] = $cardRow['balance'];
            }
            if (null !== ($cardRow['available'] ?? null)) {
                $existing['available'] = $cardRow['available'];
            }
            if ('' !== trim((string)($cardRow['currency'] ?? ''))) {
                $existing['currency'] = $cardRow['currency'];
            }
            if ('' !== trim((string)($cardRow['iban'] ?? ''))) {
                $existing['iban'] = (string)$cardRow['iban'];
            }
            if ('' !== trim((string)($cardRow['bban'] ?? ''))) {
                $existing['bban'] = (string)$cardRow['bban'];
            }
            $existingExtra = is_array($existing['extra'] ?? null) ? $existing['extra'] : [];
            $cardExtra = is_array($cardRow['extra'] ?? null) ? $cardRow['extra'] : [];
            if ([] !== $cardExtra) {
                $existing['extra'] = array_merge($existingExtra, $cardExtra);
            }
            $existing['is_card'] = (bool)($existing['is_card'] ?? false) || (bool)($cardRow['is_card'] ?? false);
            $merged[$existingIndex] = $existing;

            foreach ($this->getAccountSyncIds($existing) as $syncId) {
                $identityIndex[$this->normalizeAccountKey($syncId)] = $existingIndex;
            }
        }

        return $merged;
    }

    private function getTransactionRowsFromWebSession(array $accounts = []): array
    {
        try {
            $booked = $this->fetchPagedTransactions(false);
            $pending = $this->fetchPagedTransactions(true);
        } catch (ImporterHttpException $e) {
            if (!$this->shouldUseStatementFallback($e)) {
                throw $e;
            }
            logger()->warning(sprintf('BasisBank account-discovery fallback to statement parser after CardModule error: %s', $e->getMessage()));

            return $this->fetchStatementTransactionRowsForAccounts($accounts);
        }

        $rows = array_merge($booked, $pending);
        if ([] === $rows) {
            $statementRows = $this->fetchStatementTransactionRowsForAccounts($accounts);
            if ([] !== $statementRows) {
                return $statementRows;
            }
        }

        return $rows;
    }

    private function fetchPagedTransactions(bool $blockedOnly): array
    {
        $startDate = Carbon::now()->subYears(self::TRANSACTION_DISCOVERY_LOOKBACK_YEARS)->format(self::DATE_FORMAT);
        $endDate = Carbon::now()->format(self::DATE_FORMAT);
        $rows = [];
        $seen = [];
        $signatures = [];

        for ($page = 1; $page <= self::DEFAULT_MAX_PAGES; $page++) {
            $payload = $this->callCardModuleWithSessionRecovery('getlasttransactionlist', [
                'StartDate'   => $startDate,
                'EndDate'     => $endDate,
                'SearchWord'  => '',
                'PageNumber'  => (string)$page,
                'JustBlocked' => $blockedOnly ? '1' : '0',
                'AccountIban' => '',
            ]);

            if (!is_array($payload)) {
                break;
            }

            $pageRows = $this->extractRowsFromTransactionPayload($payload);
            if ([] === $pageRows) {
                break;
            }

            $firstId = '';
            $lastId = '';
            foreach ($pageRows as $pageRow) {
                if (!is_array($pageRow)) {
                    continue;
                }
                if ('' === $firstId) {
                    $firstId = (string)($pageRow['TransactionID'] ?? '');
                }
                $lastId = (string)($pageRow['TransactionID'] ?? $lastId);
            }
            $signature = sprintf(
                '%s|%d|%s|%s',
                $blockedOnly ? 'blocked' : 'booked',
                count($pageRows),
                $firstId,
                $lastId
            );
            if (isset($signatures[$signature])) {
                break;
            }
            $signatures[$signature] = true;

            foreach ($pageRows as $pageRow) {
                if (!is_array($pageRow)) {
                    continue;
                }
                $rowId = trim((string)($pageRow['TransactionID'] ?? $pageRow['TransactionReference'] ?? $pageRow['TransferID'] ?? ''));
                if ('' !== $rowId && isset($seen[$rowId])) {
                    continue;
                }
                if ('' !== $rowId) {
                    $seen[$rowId] = true;
                }
                $rows[] = $pageRow;
            }

            if (count($pageRows) < self::DEFAULT_PAGE_SIZE) {
                break;
            }
        }

        return $rows;
    }

    private function shouldUseStatementFallback(ImporterHttpException $e): bool
    {
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

    private function fetchStatementTransactionRowsForAccounts(array $accounts): array
    {
        $statementIds = [];
        foreach ($accounts as $account) {
            if (!is_array($account)) {
                continue;
            }
            if ((bool)($account['is_card'] ?? false)) {
                continue;
            }
            $statementId = $this->resolveStatementIdFromAccount($account);
            if ('' === $statementId) {
                continue;
            }
            $statementIds[$statementId] = true;
        }
        if ([] === $statementIds) {
            return [];
        }

        $rows = [];
        foreach (array_keys($statementIds) as $statementId) {
            try {
                $rows = array_merge($rows, $this->fetchStatementTransactionRowsForStatement((string)$statementId));
            } catch (ImporterHttpException $e) {
                if ($this->isSessionRecoveryCandidate($e)) {
                    throw $e;
                }
                logger()->warning(sprintf('BasisBank statement fallback parser skipped account "%s": %s', $statementId, $e->getMessage()));
            }
        }

        return $rows;
    }

    private function enrichNonCardAccountsWithAmountHints(array $accounts): array
    {
        $globalInfoHints = $this->fetchGlobalInfoAmountHints();
        $viewStateAccountMap = $this->buildStatementViewStateAccountMap($accounts);

        foreach ($accounts as $index => $account) {
            if (!is_array($account)) {
                continue;
            }
            if ((bool)($account['is_card'] ?? false)) {
                continue;
            }

            $needsBalance = null === ($account['balance'] ?? null) || null === ($account['available'] ?? null);
            $needsCurrency = '' === CurrencyCode::normalizeOrEmpty((string)($account['currency'] ?? ''));
            if (!$needsBalance && !$needsCurrency) {
                continue;
            }

            $statementId = $this->resolveStatementIdFromAccount($account);
            if ('' === $statementId) {
                continue;
            }

            $mapped = $viewStateAccountMap[$statementId] ?? null;
            if (is_array($mapped)) {
                $mappedCurrency = CurrencyCode::normalizeOrEmpty((string)($mapped['currency'] ?? ''));
                $mappedIban = trim((string)($mapped['iban'] ?? ''));
                $mappedKind = trim((string)($mapped['kind'] ?? ''));
                if ($needsCurrency && '' !== $mappedCurrency) {
                    $account['currency'] = $mappedCurrency;
                    $needsCurrency = false;
                }
                if ('' === trim((string)($account['iban'] ?? '')) && '' !== $mappedIban) {
                    $account['iban'] = $mappedIban;
                }
                $extra = is_array($account['extra'] ?? null) ? $account['extra'] : [];
                if ('' !== $mappedCurrency) {
                    $extra['Currency hint'] = $mappedCurrency;
                    $extra['Amount hints source'] = 'Statement.aspx account map';
                }
                if ('' !== $mappedIban) {
                    $extra['Mapped IBAN'] = $mappedIban;
                }
                if ('' !== $mappedKind) {
                    $extra['Mapped account kind'] = $mappedKind;
                }
                if ('' !== $mappedIban || '' !== $mappedCurrency) {
                    $compact = trim(sprintf('%s/%s', $mappedIban, $mappedCurrency), '/');
                    if ('' !== $compact) {
                        $extra['Mapped statement option'] = $compact;
                    }
                }
                if ([] !== $extra) {
                    $account['extra'] = $extra;
                }
                $accounts[$index] = $account;
                logger()->debug(
                    sprintf(
                        'BasisBank viewstate account-map applied for account "%s": currency="%s", iban="%s", kind="%s".',
                        $statementId,
                        $mappedCurrency,
                        $mappedIban,
                        $mappedKind
                    )
                );
                if (!$needsBalance && !$needsCurrency) {
                    continue;
                }
            }

            try {
                $html = $this->requestStatementPageWithSessionRecovery($statementId);
            } catch (ImporterHttpException $e) {
                if ($this->isSessionRecoveryCandidate($e)) {
                    throw $e;
                }
                logger()->warning(sprintf('BasisBank amount-hint enrichment skipped for account "%s": %s', $statementId, $e->getMessage()));
                continue;
            }

            $hints = $this->extractAmountHintsFromStatementAccountHtml($html, $statementId);
            if ([] === $hints) {
                $hints = $this->extractAmountHintsFromHtml($html);
            }
            if ([] === $hints) {
                $hints = $this->extractAmountHintsFromStatementViewState($html, $statementId);
            }
            if ([] === $hints && [] !== $globalInfoHints) {
                $hints = $globalInfoHints;
            }
            if ([] === $hints) {
                continue;
            }

            $hintCurrency = CurrencyCode::normalizeOrEmpty((string)($hints['currency'] ?? ''));
            $hintSource = trim((string)($hints['source'] ?? ''));
            $isViewStateHint = str_contains(strtolower($hintSource), '__viewstate');
            if ($needsCurrency && '' !== $hintCurrency && !$isViewStateHint) {
                $account['currency'] = $hintCurrency;
            }

            $hintBalance = $hints['balance'] ?? null;
            $hintBalanceNumeric = is_numeric($hintBalance) ? (float)$hintBalance : null;
            if ($isViewStateHint && null !== $hintBalanceNumeric && abs($hintBalanceNumeric) < 0.000001) {
                // Ignore zero-only viewstate hints; they are frequently placeholders.
                $hintBalanceNumeric = null;
            }
            if ($needsBalance && null !== $hintBalanceNumeric) {
                $account['balance'] = $hintBalanceNumeric;
                $account['available'] = $hintBalanceNumeric;
            }

            $display = trim((string)($hints['display'] ?? ''));
            $extra = is_array($account['extra'] ?? null) ? $account['extra'] : [];
            if ('' !== $display && !$isViewStateHint) {
                $extra['Amount hints'] = $display;
            }
            if ('' !== $hintSource && (!$isViewStateHint || null !== $hintBalanceNumeric)) {
                $extra['Amount hints source'] = $hintSource;
            } elseif ([] !== $globalInfoHints && $hints === $globalInfoHints) {
                $extra['Amount hints source'] = 'Info.aspx (session-wide)';
            }
            if ('' !== $hintCurrency && (!$isViewStateHint || '' === trim((string)($extra['Currency hint'] ?? '')))) {
                $extra['Currency hint'] = $hintCurrency;
            }
            if (null !== $hintBalanceNumeric) {
                $extra['Balance hint'] = (string)$hintBalanceNumeric;
            }
            if ([] !== $extra) {
                $account['extra'] = $extra;
            }

            logger()->debug(
                sprintf(
                    'BasisBank amount-hint enrichment applied for account "%s": source="%s", currency="%s", balance="%s".',
                    $statementId,
                    $hintSource,
                    $hintCurrency,
                    null !== $hintBalanceNumeric ? (string)$hintBalanceNumeric : ''
                )
            );

            $accounts[$index] = $account;
        }

        return $accounts;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function buildStatementViewStateAccountMap(array $accounts): array
    {
        $knownIds = [];
        foreach ($accounts as $account) {
            if (!is_array($account)) {
                continue;
            }
            if ((bool)($account['is_card'] ?? false)) {
                continue;
            }
            $statementId = $this->resolveStatementIdFromAccount($account);
            if ('' === $statementId) {
                continue;
            }
            $knownIds[$statementId] = true;
        }
        if ([] === $knownIds) {
            return [];
        }

        $seedStatementId = (string)array_key_first($knownIds);
        try {
            $html = $this->requestStatementPageWithSessionRecovery($seedStatementId);
        } catch (ImporterHttpException $e) {
            if ($this->isSessionRecoveryCandidate($e)) {
                throw $e;
            }
            logger()->warning(sprintf('BasisBank statement viewstate account-map bootstrap failed for account "%s": %s', $seedStatementId, $e->getMessage()));

            return [];
        }

        $knownStatementIds = array_keys($knownIds);

        $htmlMap = $this->extractStatementAccountMapFromHtmlOptions($html, $knownStatementIds);
        if ([] !== $htmlMap) {
            logger()->debug(sprintf('BasisBank statement account-map built from statement-page HTML options with %d mapped account(s).', count($htmlMap)));

            return $htmlMap;
        }

        $map = $this->extractStatementViewStateAccountMap($html, $knownStatementIds);
        if ([] !== $map) {
            logger()->debug(sprintf('BasisBank statement viewstate account-map built with %d mapped account(s).', count($map)));

            return $map;
        }

        try {
            $balanceHtml = $this->requestBalancePageWithSessionRecovery();
        } catch (ImporterHttpException $e) {
            if ($this->isSessionRecoveryCandidate($e)) {
                throw $e;
            }
            logger()->warning(sprintf('BasisBank statement account-map balance-page fallback failed: %s', $e->getMessage()));
            logger()->warning('BasisBank statement viewstate account-map parser returned no mappings.');

            return [];
        }

        $balanceHtmlMap = $this->extractStatementAccountMapFromHtmlOptions($balanceHtml, $knownStatementIds);
        if ([] !== $balanceHtmlMap) {
            logger()->debug(sprintf('BasisBank statement account-map built from balance-page HTML options with %d mapped account(s).', count($balanceHtmlMap)));

            return $balanceHtmlMap;
        }

        $balanceViewStateMap = $this->extractStatementViewStateAccountMap($balanceHtml, $knownStatementIds);
        if ([] !== $balanceViewStateMap) {
            logger()->debug(sprintf('BasisBank statement viewstate account-map built from balance-page with %d mapped account(s).', count($balanceViewStateMap)));

            return $balanceViewStateMap;
        }

        logger()->warning('BasisBank statement viewstate account-map parser returned no mappings.');

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchGlobalInfoAmountHints(): array
    {
        try {
            $html = $this->requestInfoPageWithSessionRecovery();
        } catch (ImporterHttpException $e) {
            if ($this->isSessionRecoveryCandidate($e)) {
                throw $e;
            }
            logger()->warning(sprintf('BasisBank global info-page amount hints unavailable: %s', $e->getMessage()));

            return [];
        }

        $hints = $this->extractAmountHintsFromHtml($html);
        if ([] === $hints) {
            logger()->warning('BasisBank global info-page amount hints parser returned no values.');
        }

        return $hints;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractAmountHintsFromHtml(string $html): array
    {
        $text = $this->normalizeWhitespace(strip_tags($html));
        if ('' === $text) {
            return [];
        }

        $pairs = $this->extractAmountCurrencyPairs([$text], true);
        $hints = $this->buildAmountHintsFromPairs($pairs);
        if ([] === $hints) {
            return [];
        }

        return $hints;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractAmountHintsFromStatementAccountHtml(string $html, string $statementId): array
    {
        $scope = $this->extractStatementAccountHintCandidates($html, $statementId);
        if ([] === $scope) {
            return [];
        }

        $pairs = $this->extractAmountCurrencyPairs($scope, true);
        $hints = $this->buildAmountHintsFromPairs($pairs);
        if ([] === $hints) {
            return [];
        }
        $hints['source'] = 'Statement.aspx account scope';

        return $hints;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractAmountHintsFromStatementViewState(string $html, string $statementId): array
    {
        $viewState = $this->extractViewStateText($html);
        if ('' === $viewState) {
            return [];
        }

        $scope = [];
        $id = trim($statementId);
        if ('' !== $id) {
            $matches = [];
            preg_match_all('/.{0,200}' . preg_quote($id, '/') . '.{0,320}/u', $viewState, $matches);
            if (isset($matches[0]) && [] !== $matches[0]) {
                foreach ($matches[0] as $fragment) {
                    if (!is_string($fragment)) {
                        continue;
                    }
                    $candidate = $this->normalizeWhitespace($fragment);
                    if ('' !== $candidate) {
                        $scope[] = $candidate;
                    }
                }
            }
        }
        $scope[] = $viewState;

        $pairs = $this->extractAmountCurrencyPairs($scope, true);
        $hints = $this->buildAmountHintsFromPairs($pairs);
        if ([] === $hints) {
            return [];
        }
        $hints['source'] = 'Statement.aspx __VIEWSTATE';

        return $hints;
    }

    private function extractViewStateText(string $html): string
    {
        if ('' === trim($html)) {
            return '';
        }

        $document = new \DOMDocument();
        $loaded = @ $document->loadHTML($html);
        if (false === $loaded) {
            return '';
        }
        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('//input[@name="__VIEWSTATE" or @id="__VIEWSTATE"]');
        if (false === $nodes || 0 === $nodes->length) {
            return '';
        }
        $node = $nodes->item(0);
        if (!$node instanceof DOMElement) {
            return '';
        }
        $encoded = trim((string)$node->getAttribute('value'));
        if ('' === $encoded) {
            return '';
        }
        $encoded = html_entity_decode($encoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = base64_decode($encoded, true);
        if (false === $decoded) {
            return '';
        }

        $raw = (string)$decoded;
        if ('' === $raw) {
            return '';
        }

        $printable = preg_replace('/[^\x20-\x7E]+/', ' ', $raw);
        if (!is_string($printable)) {
            $printable = '';
        }

        if ('' === trim($printable)) {
            $utf16Decoded = @iconv('UTF-16LE', 'UTF-8//IGNORE', $raw);
            if (false !== $utf16Decoded && '' !== trim((string)$utf16Decoded)) {
                $converted = preg_replace('/[^\x20-\x7E]+/', ' ', (string)$utf16Decoded);
                if (is_string($converted)) {
                    $printable = $converted;
                }
            }
        }

        return '' === trim($printable) ? '' : $this->normalizeWhitespace($printable);
    }

    /**
     * @param array<int, string> $knownStatementIds
     * @return array<string, array<string, string>>
     */
    private function extractStatementAccountMapFromHtmlOptions(string $html, array $knownStatementIds): array
    {
        if ('' === trim($html)) {
            return [];
        }

        $known = [];
        foreach ($knownStatementIds as $statementId) {
            if (!is_string($statementId)) {
                continue;
            }
            $normalized = trim($statementId);
            if ('' === $normalized) {
                continue;
            }
            $known[$normalized] = true;
        }

        $document = new \DOMDocument();
        $loaded = @ $document->loadHTML($html);
        if (false === $loaded) {
            return [];
        }

        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('//select[contains(@name, "AccountDDL") or contains(@id, "AccountDDL")]/option');
        if (false === $nodes || 0 === $nodes->length) {
            return [];
        }

        $map = [];
        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $statementId = trim((string)$node->getAttribute('value'));
            if ('' === $statementId) {
                continue;
            }
            if ([] !== $known && !isset($known[$statementId])) {
                continue;
            }
            if (isset($map[$statementId])) {
                continue;
            }

            $label = $this->normalizeWhitespace((string)$node->textContent);
            if ('' === $label) {
                continue;
            }

            $currency = CurrencyCode::normalizeOrEmpty((string)($this->extractCurrency($label) ?? ''));
            $iban = $this->extractIban($label);
            $kind = preg_match('/\b(card|mastercard|visa)\b/i', $label) === 1 ? 'card' : 'account';

            $map[$statementId] = [
                'currency' => $currency,
                'iban'     => $iban,
                'kind'     => $kind,
                'label'    => $label,
            ];
        }

        return $map;
    }

    /**
     * @param array<int, string> $knownStatementIds
     * @return array<string, array<string, string>>
     */
    private function extractStatementViewStateAccountMap(string $html, array $knownStatementIds): array
    {
        $viewState = $this->extractViewStateText($html);
        if ('' === $viewState || [] === $knownStatementIds) {
            return [];
        }

        $options = $this->extractStatementAccountOptionsFromViewState($viewState);
        if ([] === $options) {
            return [];
        }

        $orderedIds = $this->orderKnownIdsByPositionInText($viewState, $knownStatementIds);
        if ([] === $orderedIds) {
            return [];
        }

        $limit = min(count($orderedIds), count($options));
        if ($limit <= 0) {
            return [];
        }
        if (count($orderedIds) !== count($options)) {
            logger()->warning(
                sprintf(
                    'BasisBank statement viewstate map size mismatch: ids=%d options=%d; mapping first %d in order.',
                    count($orderedIds),
                    count($options),
                    $limit
                )
            );
        }

        $map = [];
        for ($i = 0; $i < $limit; $i++) {
            $statementId = $orderedIds[$i];
            $option = $options[$i];
            if (!is_string($statementId) || '' === trim($statementId) || !is_array($option)) {
                continue;
            }
            $currency = CurrencyCode::normalizeOrEmpty((string)($option['currency'] ?? ''));
            if ('' === $currency) {
                continue;
            }
            $map[$statementId] = [
                'currency' => $currency,
                'iban'     => trim((string)($option['iban'] ?? '')),
                'kind'     => trim((string)($option['kind'] ?? '')),
                'label'    => trim((string)($option['label'] ?? '')),
            ];
        }

        return $map;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function extractStatementAccountOptionsFromViewState(string $viewState): array
    {
        if ('' === trim($viewState)) {
            return [];
        }

        $currencies = 'AED|ARS|AUD|AZN|BGN|BRL|BSD|CAD|CHF|CLP|CNY|COP|CRC|CZK|DKK|DOP|DZD|EGP|EUR|GBP|GEL|HKD|HRK|HUF|IDR|ILS|INR|JPY|KGS|KZT|MDL|MXN|NOK|PEN|PHP|PKR|PLN|RON|RSD|RUB|SEK|SGD|THB|TRY|UAH|USD|UZS|VND|ZAR';
        $pattern = '/([A-Z]{2}\d{2}[A-Z0-9]{8,})\/(' . $currencies . ')\s+(C\/A|ACCOUNT|CARD(?:\s+ACC|ACCOUNT))/';
        $matches = [];
        preg_match_all($pattern, $viewState, $matches, PREG_SET_ORDER);
        if ([] === $matches) {
            return [];
        }

        $options = [];
        $seen = [];
        foreach ($matches as $match) {
            $iban = trim((string)($match[1] ?? ''));
            $currency = CurrencyCode::normalizeOrEmpty((string)($match[2] ?? ''));
            $kindToken = strtoupper(trim((string)($match[3] ?? '')));
            if ('' === $iban || '' === $currency) {
                continue;
            }
            $kind = str_contains($kindToken, 'CARD') ? 'card' : 'account';
            $key = sprintf('%s|%s|%s', $iban, $currency, $kind);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $options[] = [
                'iban'     => $iban,
                'currency' => $currency,
                'kind'     => $kind,
                'label'    => sprintf('%s/%s %s', $iban, $currency, $kindToken),
            ];
        }

        return $options;
    }

    /**
     * @param array<int, string> $knownStatementIds
     * @return array<int, string>
     */
    private function orderKnownIdsByPositionInText(string $text, array $knownStatementIds): array
    {
        if ('' === trim($text) || [] === $knownStatementIds) {
            return [];
        }

        $positions = [];
        foreach ($knownStatementIds as $id) {
            if (!is_string($id) || '' === trim($id)) {
                continue;
            }
            $position = strpos($text, $id);
            if (false === $position) {
                continue;
            }
            $positions[$id] = $position;
        }
        if ([] === $positions) {
            return [];
        }

        asort($positions);

        return array_keys($positions);
    }

    /**
     * @return array<int, string>
     */
    private function extractStatementAccountHintCandidates(string $html, string $statementId): array
    {
        $id = trim($statementId);
        if ('' === $id) {
            return [];
        }

        $candidates = [];

        $document = new \DOMDocument();
        $loaded = @ $document->loadHTML($html);
        if (false !== $loaded) {
            $xpath = new DOMXPath($document);
            $options = $xpath->query('//select[contains(@name, "AccountDDL") or contains(@id, "AccountDDL")]/option');
            if (false !== $options) {
                foreach ($options as $option) {
                    if (!$option instanceof DOMElement) {
                        continue;
                    }
                    $value = trim((string)$option->getAttribute('value'));
                    $text = $this->normalizeWhitespace((string)$option->textContent);
                    if ('' === $text) {
                        continue;
                    }
                    if ($id === $value || str_contains($text, $id)) {
                        $candidates[] = $text;
                    }
                }
            }
        }

        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ('' !== $decoded) {
            $pattern = '/.{0,180}' . preg_quote($id, '/') . '.{0,260}/u';
            $matches = [];
            preg_match_all($pattern, $decoded, $matches);
            if (isset($matches[0]) && [] !== $matches[0]) {
                foreach ($matches[0] as $fragment) {
                    if (!is_string($fragment)) {
                        continue;
                    }
                    $text = $this->normalizeWhitespace(strip_tags($fragment));
                    if ('' !== $text) {
                        $candidates[] = $text;
                    }
                }
            }
        }

        $result = [];
        foreach ($candidates as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            $normalized = $this->normalizeWhitespace($candidate);
            if ('' === $normalized || !str_contains($normalized, $id)) {
                continue;
            }
            if (!in_array($normalized, $result, true)) {
                $result[] = $normalized;
            }
        }

        return $result;
    }

    /**
     * @param array<int, string>|string $text
     * @return array<int, array{amount: float, currency: string}>
     */
    private function extractAmountCurrencyPairs(array|string $text, bool $allowZero): array
    {
        $parts = is_array($text) ? $text : [$text];
        if ([] === $parts) {
            return [];
        }

        $currencies = 'AED|ARS|AUD|AZN|BGN|BRL|BSD|CAD|CHF|CLP|CNY|COP|CRC|CZK|DKK|DOP|DZD|EGP|EUR|GBP|GEL|HKD|HRK|HUF|IDR|ILS|INR|JPY|KGS|KZT|MDL|MXN|NOK|PEN|PHP|PKR|PLN|RON|RSD|RUB|SEK|SGD|THB|TRY|UAH|USD|UZS|VND|ZAR';
        $patternRight = '/(-?\d[\d\s,.()]{0,20})\s*(' . $currencies . '|₾|€|\\$)/u';
        $patternLeft = '/(' . $currencies . '|₾|€|\\$)\s*(-?\d[\d\s,.()]{0,20})/u';

        $pairs = [];
        foreach ($parts as $part) {
            if (!is_string($part)) {
                continue;
            }
            $value = $this->normalizeWhitespace($part);
            if ('' === $value) {
                continue;
            }

            $matches = [];
            preg_match_all($patternRight, $value, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $currency = $this->normalizeCurrencyToken((string)($match[2] ?? ''));
                $amount = $this->parseAmountValue((string)($match[1] ?? ''));
                if ('' === $currency) {
                    continue;
                }
                if (!$allowZero && abs($amount) < 0.000001) {
                    continue;
                }
                if (abs($amount) > 1000000000) {
                    continue;
                }
                $pairs[] = ['amount' => $amount, 'currency' => $currency];
            }

            $reverseMatches = [];
            preg_match_all($patternLeft, $value, $reverseMatches, PREG_SET_ORDER);
            foreach ($reverseMatches as $match) {
                $currency = $this->normalizeCurrencyToken((string)($match[1] ?? ''));
                $amount = $this->parseAmountValue((string)($match[2] ?? ''));
                if ('' === $currency) {
                    continue;
                }
                if (!$allowZero && abs($amount) < 0.000001) {
                    continue;
                }
                if (abs($amount) > 1000000000) {
                    continue;
                }
                $pairs[] = ['amount' => $amount, 'currency' => $currency];
            }
        }

        return $pairs;
    }

    private function normalizeCurrencyToken(string $token): string
    {
        $currencyRaw = strtoupper(trim($token));
        if ('₾' === $currencyRaw) {
            $currencyRaw = 'GEL';
        }
        if ('€' === $currencyRaw) {
            $currencyRaw = 'EUR';
        }
        if ('$' === $currencyRaw) {
            $currencyRaw = 'USD';
        }

        return CurrencyCode::normalizeOrEmpty($currencyRaw);
    }

    /**
     * @param array<int, array{amount: float, currency: string}> $pairs
     * @return array<string, mixed>
     */
    private function buildAmountHintsFromPairs(array $pairs): array
    {
        if ([] === $pairs) {
            return [];
        }

        $seen = [];
        $unique = [];
        foreach ($pairs as $pair) {
            $key = sprintf('%s|%s', number_format((float)$pair['amount'], 2, '.', ''), (string)$pair['currency']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $pair;
            if (count($unique) >= 10) {
                break;
            }
        }
        if ([] === $unique) {
            return [];
        }

        $displayParts = [];
        foreach ($unique as $pair) {
            $displayParts[] = sprintf('%s %s', number_format((float)$pair['amount'], 2, '.', ''), (string)$pair['currency']);
        }

        $counter = [];
        foreach ($unique as $pair) {
            $currency = (string)$pair['currency'];
            if (!array_key_exists($currency, $counter)) {
                $counter[$currency] = 0;
            }
            $counter[$currency]++;
        }
        arsort($counter);
        $dominantCurrency = '';
        if ([] !== $counter) {
            $max = max($counter);
            $top = [];
            foreach ($counter as $currency => $count) {
                if ($count === $max) {
                    $top[] = $currency;
                }
            }
            if (1 === count($top)) {
                $dominantCurrency = (string)$top[0];
            }
        }

        $balanceHint = null;
        $positive = [];
        foreach ($unique as $pair) {
            if ($dominantCurrency !== '' && 0 !== strcasecmp((string)$pair['currency'], $dominantCurrency)) {
                continue;
            }
            if ((float)$pair['amount'] >= 0.0) {
                $positive[] = (float)$pair['amount'];
            }
        }
        if ([] !== $positive) {
            $balanceHint = max($positive);
        } else {
            $first = $unique[0] ?? null;
            if (is_array($first) && isset($first['amount']) && is_numeric($first['amount'])) {
                $balanceHint = (float)$first['amount'];
            }
        }

        return [
            'currency' => $dominantCurrency,
            'balance'  => $balanceHint,
            'display'  => implode(', ', $displayParts),
        ];
    }

    /**
     * @throws ImporterHttpException
     */
    private function requestInfoPageWithSessionRecovery(): string
    {
        $attempt = 0;
        while (true) {
            try {
                return $this->requestInfoPage();
            } catch (ImporterHttpException $e) {
                if (!$this->isSessionRecoveryCandidate($e) || $attempt >= self::MAX_SESSION_RECOVERY_ATTEMPTS) {
                    throw $e;
                }
                $attempt++;
                $this->recoverWebSessionForCardModule('info-page', $e);
            }
        }
    }

    /**
     * @throws ImporterHttpException
     */
    private function requestInfoPage(): string
    {
        $cookies = $this->getSessionCookies();
        if ([] === $cookies) {
            throw new ImporterHttpException('BasisBank web session is not available for info-page retrieval.');
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
                'GET',
                self::INFO_PAGE_PATH,
                [
                    'headers' => [
                        'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                        'Cookie'     => $this->buildCookieHeader($cookies),
                        'Referer'    => sprintf('%s/Balance.aspx', self::BASE_WEB_URL),
                        'User-Agent' => sprintf('FF3-data-importer/%s (%s)', config('importer.version'), config('importer.line_b')),
                    ],
                    'allow_redirects' => false,
                ]
            );
        } catch (TransferException $e) {
            $httpException = new ImporterHttpException(sprintf('BasisBank info-page request failed: %s', $e->getMessage()), 0, $e);
            $httpException->statusCode = method_exists($e, 'getResponse') && null !== $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
            throw $httpException;
        }

        $status = (int)$response->getStatusCode();
        $this->updateSessionCookiesFromResponse($response);
        if (401 === $status || 403 === $status || 440 === $status) {
            $httpException = new ImporterHttpException(sprintf('BasisBank info-page denied access (%d).', $status));
            $httpException->statusCode = $status;
            throw $httpException;
        }
        if ($status >= 300) {
            $httpException = new ImporterHttpException(sprintf('BasisBank info-page returned HTTP %d.', $status));
            $httpException->statusCode = $status;
            throw $httpException;
        }

        $html = (string)$response->getBody();
        if ($this->containsLoginForm($html)) {
            throw new ImporterHttpException('BasisBank info-page returned login form, session is not authorized.');
        }

        return $html;
    }

    private function resolveStatementIdFromAccount(array $account): string
    {
        $bban = trim((string)($account['bban'] ?? ''));
        if (preg_match('/^\d+$/', $bban) === 1) {
            return $bban;
        }

        $id = trim((string)($account['id'] ?? ''));
        if (str_contains($id, '#')) {
            [$id] = explode('#', $id, 2);
            $id = trim((string)$id);
        }
        if (preg_match('/^bb-account-(.+)$/i', $id, $matches) === 1) {
            $id = trim((string)$matches[1]);
        }
        if (preg_match('/^\d+$/', $id) === 1) {
            return $id;
        }

        foreach ($this->getAccountSyncIds($account) as $syncId) {
            if (preg_match('/^\d+$/', $syncId) === 1) {
                return $syncId;
            }
            if (preg_match('/^bb-account-(.+)$/i', $syncId, $matches) === 1) {
                $candidate = trim((string)$matches[1]);
                if (preg_match('/^\d+$/', $candidate) === 1) {
                    return $candidate;
                }
            }
        }

        return '';
    }

    /**
     * @return array<int, array<string, mixed>>
     * @throws ImporterHttpException
     */
    private function fetchStatementTransactionRowsForStatement(string $statementId): array
    {
        $html = $this->requestStatementPageWithSessionRecovery($statementId);

        return $this->parseStatementTransactionRowsFromHtml($html, $statementId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseStatementTransactionRowsFromHtml(string $html, string $statementId): array
    {
        $document = new \DOMDocument();
        $loaded = @ $document->loadHTML($html);
        if (false === $loaded) {
            return [];
        }

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

            $joined = implode(' ', $texts);
            $dateMatch = preg_match('/\b(\d{2}[.\/-]\d{2}[.\/-]\d{4}|\d{4}[.\/-]\d{2}[.\/-]\d{2})\b/u', $joined) === 1;
            if (!$dateMatch) {
                continue;
            }

            $currency = CurrencyCode::normalizeOrEmpty($this->extractCurrency($joined) ?? '');
            if ('' === $currency) {
                continue;
            }

            $transactionId = $this->extractStatementTransactionId($texts);
            if ('' === $transactionId) {
                $transactionId = md5((string)json_encode([$statementId, $joined]));
            }
            if (isset($seen[$transactionId])) {
                continue;
            }
            $seen[$transactionId] = true;

            $accountIban = $this->extractIban($joined);
            $parsed[] = [
                'TransactionID'         => $transactionId,
                'TransactionReference'  => $transactionId,
                'AccountIban'           => $accountIban,
                'AccountIbanEncrypted'  => '',
                'MainAccountID'         => $statementId,
                'Ccy'                   => $currency,
                'Description'           => $joined,
            ];
        }

        return $parsed;
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

    /**
     * @throws ImporterHttpException
     */
    private function requestStatementPageWithSessionRecovery(string $statementId): string
    {
        $attempt = 0;
        while (true) {
            try {
                return $this->requestStatementPage($statementId);
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
    private function requestStatementPage(string $statementId): string
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

        try {
            $response = $client->request(
                'GET',
                sprintf('%s?ID=%s', self::STATEMENT_PAGE_PATH, urlencode($statementId)),
                [
                    'headers' => [
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

        return $this->hydrateStatementPageWithPostback($client, $statementId, $html);
    }

    private function hydrateStatementPageWithPostback(Client $client, string $statementId, string $html): string
    {
        $payload = $this->buildStatementPostPayload($html, $statementId);
        if ([] === $payload) {
            return $html;
        }

        try {
            $response = $client->request(
                'POST',
                sprintf('%s?ID=%s', self::STATEMENT_PAGE_PATH, urlencode($statementId)),
                [
                    'headers' => [
                        'Accept'       => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                        'Cookie'       => $this->buildCookieHeader($this->getSessionCookies()),
                        'Referer'      => sprintf('%s%s?ID=%s', self::BASE_WEB_URL, self::STATEMENT_PAGE_PATH, urlencode($statementId)),
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
    private function buildStatementPostPayload(string $html, string $statementId): array
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

        $start = Carbon::now()->subYears(self::TRANSACTION_DISCOVERY_LOOKBACK_YEARS);
        $end = Carbon::now();
        $defaults = [
            'ctl00$Content$CurDateTxt'     => $start->format(self::DATE_FORMAT),
            'ctl00$Content$CurDateTxtEnd'  => $end->format(self::DATE_FORMAT),
            'ctl00$Content$DDLday'         => $start->format('d'),
            'ctl00$Content$DDLmounth'      => $start->format('n'),
            'ctl00$Content$DDLyear'        => $start->format('Y'),
            'ctl00$Content$DDLdayEnd'      => $end->format('d'),
            'ctl00$Content$DDLmounthEnd'   => $end->format('n'),
            'ctl00$Content$DDLyearEnd'     => $end->format('Y'),
            'ctl00$Content$ReportType'     => '3',
            '__EVENTTARGET'                => '',
            '__EVENTARGUMENT'              => '',
        ];
        foreach ($defaults as $key => $value) {
            if (array_key_exists($key, $payload) || str_starts_with($key, '__')) {
                $payload[$key] = (string)$value;
            }
        }
        foreach (array_keys($payload) as $key) {
            if (preg_match('/\\$Content\\$.*(Search|ReCount)/i', $key) === 1 && '' === trim((string)$payload[$key])) {
                $payload[$key] = 'Search';
            }
        }

        return $payload;
    }

    private function extractRowsFromTransactionPayload(array $payload): array
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

    private function ensureAccountsForTransactions(array $accounts, array $transactions): array
    {
        $known = [];
        foreach ($accounts as $account) {
            if (!is_array($account) || !isset($account['id'])) {
                continue;
            }
            foreach ($this->getAccountSyncIds($account) as $syncId) {
                $known[$this->normalizeAccountKey($syncId)] = true;
            }
        }

        foreach ($transactions as $transaction) {
            if (!is_array($transaction)) {
                continue;
            }
            $accountIban = trim((string)($transaction['AccountIban'] ?? ''));
            $encryptedIban = trim((string)($transaction['AccountIbanEncrypted'] ?? ''));
            $mainAccountId = trim((string)($transaction['MainAccountID'] ?? ''));
            $accountCandidates = $this->uniqueSyncIds(
                [
                    '' !== $accountIban ? $accountIban : null,
                    '' !== $encryptedIban ? $encryptedIban : null,
                    '' !== $mainAccountId ? $mainAccountId : null,
                    '' !== $mainAccountId ? sprintf('bb-account-%s', $mainAccountId) : null,
                ]
            );
            if ([] === $accountCandidates) {
                continue;
            }

            $alreadyKnown = false;
            foreach ($accountCandidates as $candidate) {
                if (isset($known[$this->normalizeAccountKey($candidate)])) {
                    $alreadyKnown = true;
                    break;
                }
            }
            if ($alreadyKnown) {
                continue;
            }

            $accountId = '' !== $accountIban ? $accountIban : ('' !== $mainAccountId ? $mainAccountId : $encryptedIban);
            $currency = CurrencyCode::normalizeOrEmpty((string)($transaction['Ccy'] ?? ''));
            $isCard = str_contains((string)($transaction['CardPan'] ?? ''), '****');
            $accounts[] = [
                'id'               => $accountId,
                'name'             => sprintf('BasisBank account %s', $accountId),
                'institution_name' => 'BasisBank',
                'institution_logo' => '',
                'provider'         => 'basisbank',
                'iban'             => $accountIban,
                'bban'             => $mainAccountId,
                'currency'         => $currency,
                'status'           => 'active',
                'is_card'          => $isCard,
                'balance'          => null,
                'available'        => null,
                'extra'            => [
                    'IBAN'              => $accountIban,
                    'BBAN'              => $mainAccountId,
                    'Currency'          => $currency,
                    'Balance'           => null,
                    'Available balance' => null,
                    'Card account'      => $isCard ? 'yes' : 'no',
                ],
                self::SYNC_IDS_KEY => $accountCandidates,
            ];
            foreach ($accountCandidates as $candidate) {
                $known[$this->normalizeAccountKey($candidate)] = true;
            }
        }

        return $accounts;
    }

    private function splitAccountsByCurrency(array $accounts, array $transactions): array
    {
        $result = [];
        foreach ($accounts as $account) {
            if (!is_array($account) || !isset($account['id'])) {
                continue;
            }
            $currencies = $this->collectAccountCurrencies($account, $transactions);
            if ([] === $currencies) {
                $result[] = $this->composeCurrencyScopedAccount($account, '');

                continue;
            }
            foreach ($currencies as $currency) {
                $result[] = $this->composeCurrencyScopedAccount($account, $currency);
            }
        }

        return $this->dedupeCurrencyScopedAccounts($result);
    }

    /**
     * @param array<int, array<string, mixed>> $accounts
     * @return array<int, array<string, mixed>>
     */
    private function dedupeCurrencyScopedAccounts(array $accounts): array
    {
        $dedup = [];
        foreach ($accounts as $account) {
            if (!is_array($account)) {
                continue;
            }
            $key = $this->buildCurrencyScopedDedupeKey($account);
            if ('' === $key) {
                $key = sprintf('id:%s', strtoupper(trim((string)($account['id'] ?? ''))));
            }
            if (!isset($dedup[$key]) || !is_array($dedup[$key])) {
                $dedup[$key] = $account;
                continue;
            }
            $dedup[$key] = $this->mergeCurrencyScopedDuplicateAccounts($dedup[$key], $account);
        }

        return array_values($dedup);
    }

    /**
     * @param array<string, mixed> $account
     */
    private function buildCurrencyScopedDedupeKey(array $account): string
    {
        $currency = CurrencyCode::normalizeOrEmpty((string)($account['currency'] ?? ''));
        $iban = trim((string)($account['iban'] ?? ''));
        if ('' === $iban) {
            $extra = is_array($account['extra'] ?? null) ? $account['extra'] : [];
            $iban = trim((string)($extra['IBAN'] ?? $extra['Mapped IBAN'] ?? ''));
        }
        if ('' !== $iban && '' !== $currency) {
            return strtoupper(sprintf('%s#%s', $iban, $currency));
        }

        $extra = is_array($account['extra'] ?? null) ? $account['extra'] : [];
        $baseId = trim((string)($extra['Base ID'] ?? $account['id'] ?? ''));
        if ('' !== $baseId && '' !== $currency) {
            return strtoupper(sprintf('%s#%s', $baseId, $currency));
        }

        return '';
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $candidate
     * @return array<string, mixed>
     */
    private function mergeCurrencyScopedDuplicateAccounts(array $existing, array $candidate): array
    {
        $existingIsCard = (bool)($existing['is_card'] ?? false);
        $candidateIsCard = (bool)($candidate['is_card'] ?? false);

        $primary = $existing;
        $secondary = $candidate;
        if ($candidateIsCard && !$existingIsCard) {
            $primary = $candidate;
            $secondary = $existing;
        }

        foreach (['name', 'institution_name', 'institution_logo', 'provider', 'iban', 'bban', 'currency', 'status'] as $field) {
            if (
                '' === trim((string)($primary[$field] ?? ''))
                && '' !== trim((string)($secondary[$field] ?? ''))
            ) {
                $primary[$field] = $secondary[$field];
            }
        }
        foreach (['balance', 'available'] as $field) {
            if (null === ($primary[$field] ?? null) && null !== ($secondary[$field] ?? null)) {
                $primary[$field] = $secondary[$field];
            }
        }

        $primaryExtra = is_array($primary['extra'] ?? null) ? $primary['extra'] : [];
        $secondaryExtra = is_array($secondary['extra'] ?? null) ? $secondary['extra'] : [];
        if ([] !== $secondaryExtra) {
            $primaryExtra = array_merge($secondaryExtra, $primaryExtra);
        }

        $mergedMembers = $this->mergeGroupedSourceMembers(
            $this->extractGroupedSourceMembers($primary),
            $this->extractGroupedSourceMembers($secondary)
        );
        if (count($mergedMembers) > 1) {
            $primaryExtra['Merged sources'] = $mergedMembers;
            $primaryExtra['Merged source count'] = (string)count($mergedMembers);
        }
        if ([] !== $primaryExtra) {
            $primary['extra'] = $primaryExtra;
        }

        $primary[self::SYNC_IDS_KEY] = $this->uniqueSyncIds(array_merge($this->getAccountSyncIds($primary), $this->getAccountSyncIds($secondary)));
        $primary['is_card'] = (bool)($primary['is_card'] ?? false) || (bool)($secondary['is_card'] ?? false);

        return $primary;
    }

    /**
     * @param array<string, mixed> $account
     * @return array<int, array<string, string>>
     */
    private function extractGroupedSourceMembers(array $account): array
    {
        $members = [];
        $extra = is_array($account['extra'] ?? null) ? $account['extra'] : [];
        $raw = $extra['Merged sources'] ?? [];
        if (is_array($raw)) {
            foreach ($raw as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $normalized = $this->normalizeGroupedSourceMember($entry);
                if ([] !== $normalized) {
                    $members[] = $normalized;
                }
            }
        }

        $current = $this->buildGroupedSourceMemberFromAccount($account);
        if ([] !== $current) {
            $members[] = $current;
        }

        return $this->mergeGroupedSourceMembers($members, []);
    }

    /**
     * @param array<string, mixed> $account
     * @return array<string, string>
     */
    private function buildGroupedSourceMemberFromAccount(array $account): array
    {
        $extra = is_array($account['extra'] ?? null) ? $account['extra'] : [];
        $id = trim((string)($account['id'] ?? ''));
        $baseId = trim((string)($extra['Base ID'] ?? ''));
        if ('' === $baseId) {
            $baseId = $id;
            if (str_contains($baseId, '#')) {
                [$baseId] = explode('#', $baseId, 2);
                $baseId = trim((string)$baseId);
            }
        }
        $kind = strtolower(trim((string)($extra['Account kind'] ?? (((bool)($account['is_card'] ?? false)) ? 'card' : 'account'))));
        $currency = CurrencyCode::normalizeOrEmpty((string)($account['currency'] ?? $extra['Currency scope'] ?? $extra['Currency'] ?? ''));
        $name = trim((string)($account['name'] ?? ''));
        $iban = trim((string)($account['iban'] ?? $extra['IBAN'] ?? $extra['Mapped IBAN'] ?? ''));
        $bban = trim((string)($account['bban'] ?? $extra['BBAN'] ?? ''));

        return $this->normalizeGroupedSourceMember(
            [
                'id'       => $id,
                'base_id'  => $baseId,
                'kind'     => $kind,
                'currency' => $currency,
                'name'     => $name,
                'iban'     => $iban,
                'bban'     => $bban,
            ]
        );
    }

    /**
     * @param array<string, mixed> $member
     * @return array<string, string>
     */
    private function normalizeGroupedSourceMember(array $member): array
    {
        $id = trim((string)($member['id'] ?? ''));
        $baseId = trim((string)($member['base_id'] ?? ''));
        $kind = strtolower(trim((string)($member['kind'] ?? '')));
        if ('card' !== $kind) {
            $kind = 'account';
        }
        $currency = CurrencyCode::normalizeOrEmpty((string)($member['currency'] ?? ''));
        $name = trim((string)($member['name'] ?? ''));
        $iban = trim((string)($member['iban'] ?? ''));
        $bban = trim((string)($member['bban'] ?? ''));

        if ('' === $baseId) {
            $baseId = $id;
        }
        if ('' === $id && '' === $baseId) {
            return [];
        }
        if ('' === $name) {
            $name = sprintf('BasisBank %s %s', $kind, '' !== $baseId ? $baseId : $id);
        }

        return [
            'id'       => $id,
            'base_id'  => $baseId,
            'kind'     => $kind,
            'currency' => $currency,
            'name'     => $name,
            'iban'     => $iban,
            'bban'     => $bban,
        ];
    }

    /**
     * @param array<int, array<string, string>> $left
     * @param array<int, array<string, string>> $right
     * @return array<int, array<string, string>>
     */
    private function mergeGroupedSourceMembers(array $left, array $right): array
    {
        $merged = [];
        foreach (array_merge($left, $right) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $member = $this->normalizeGroupedSourceMember($entry);
            if ([] === $member) {
                continue;
            }
            $memberKey = strtoupper(sprintf(
                '%s|%s|%s',
                '' !== $member['base_id'] ? $member['base_id'] : $member['id'],
                $member['currency'],
                $member['kind']
            ));
            if (!isset($merged[$memberKey]) || !is_array($merged[$memberKey])) {
                $merged[$memberKey] = $member;
                continue;
            }

            foreach (['id', 'base_id', 'name', 'iban', 'bban', 'currency'] as $field) {
                if ('' === trim((string)($merged[$memberKey][$field] ?? '')) && '' !== trim((string)($member[$field] ?? ''))) {
                    $merged[$memberKey][$field] = $member[$field];
                }
            }
        }

        $values = array_values($merged);
        usort($values, static function (array $a, array $b): int {
            $rank = static fn (string $kind): int => 'account' === $kind ? 0 : 1;
            $ra = $rank((string)($a['kind'] ?? 'account'));
            $rb = $rank((string)($b['kind'] ?? 'account'));
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }

            return strcmp((string)($a['base_id'] ?? ''), (string)($b['base_id'] ?? ''));
        });

        return $values;
    }

    private function collectAccountCurrencies(array $account, array $transactions): array
    {
        $currencies = [];
        $direct = CurrencyCode::normalizeOrEmpty((string)($account['currency'] ?? ''));
        if ('' !== $direct) {
            $currencies[$direct] = true;
        }

        $knownSyncIds = [];
        foreach ($this->getAccountSyncIds($account) as $syncId) {
            $knownSyncIds[$this->normalizeAccountKey($syncId)] = true;
        }
        if ([] === $knownSyncIds) {
            return array_keys($currencies);
        }

        foreach ($transactions as $transaction) {
            if (!is_array($transaction)) {
                continue;
            }
            $transactionSyncIds = $this->uniqueSyncIds(
                [
                    (string)($transaction['AccountIban'] ?? ''),
                    (string)($transaction['AccountIbanEncrypted'] ?? ''),
                    (string)($transaction['MainAccountID'] ?? ''),
                    (string)($transaction['TransactionReference'] ?? ''),
                ]
            );
            $matched = false;
            foreach ($transactionSyncIds as $syncId) {
                if (isset($knownSyncIds[$this->normalizeAccountKey($syncId)])) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                continue;
            }
            $currency = CurrencyCode::normalizeOrEmpty((string)($transaction['Ccy'] ?? ''));
            if ('' === $currency) {
                continue;
            }
            $currencies[$currency] = true;
        }

        $codes = array_keys($currencies);
        sort($codes);

        return $codes;
    }

    private function composeCurrencyScopedAccount(array $account, string $currency): array
    {
        $baseId = trim((string)($account['id'] ?? ''));
        $code   = CurrencyCode::normalizeOrEmpty($currency);
        $isCard = (bool)($account['is_card'] ?? false);
        $kind   = $isCard ? 'card' : 'account';
        $scopedId = '' !== $code ? sprintf('%s#%s', $baseId, $code) : $baseId;

        $row = $account;
        $row['id'] = $scopedId;
        $row['currency'] = $code;
        if ('' !== trim((string)($row['name'] ?? '')) && '' !== $code) {
            $row['name'] = sprintf('%s (%s)', (string)$row['name'], $code);
        }
        $syncIds = $this->getAccountSyncIds($account);
        $syncIds[] = $baseId;
        $syncIds[] = sprintf('basisbank:%s:%s', $kind, $baseId);
        if ('' !== $code) {
            $syncIds[] = sprintf('basisbank:%s:%s:%s', $kind, $baseId, $code);
        }
        $syncIds[] = $scopedId;
        $row[self::SYNC_IDS_KEY] = $this->uniqueSyncIds($syncIds);
        $rowExtra = is_array($row['extra'] ?? null) ? $row['extra'] : [];
        $rowExtra['Base ID'] = $baseId;
        $rowExtra['Account kind'] = $kind;
        if ('' !== $code) {
            $rowExtra['Currency scope'] = $code;
        }
        $row['extra'] = $rowExtra;

        return $row;
    }

    private function normalizeAccountKey(string $value): string
    {
        return strtoupper(str_replace(' ', '', trim($value)));
    }

    private function getAccountSyncIds(array $account): array
    {
        $syncIds = [];
        $rowSyncIds = $account[self::SYNC_IDS_KEY] ?? [];
        if (is_array($rowSyncIds)) {
            foreach ($rowSyncIds as $syncId) {
                if (!is_string($syncId)) {
                    continue;
                }
                $syncIds[] = $syncId;
            }
        }
        $syncIds[] = (string)($account['id'] ?? '');

        return $this->uniqueSyncIds($syncIds);
    }

    private function uniqueSyncIds(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                continue;
            }
            $trimmed = trim($value);
            if ('' === $trimmed) {
                continue;
            }
            if (!in_array($trimmed, $result, true)) {
                $result[] = $trimmed;
            }
        }

        return $result;
    }

    private function parseAmounts(string|array $text): array
    {
        $values = [];
        $parts = is_array($text) ? $text : [$text];
        foreach ($parts as $part) {
            if (!is_string($part) || '' === trim($part)) {
                continue;
            }
            $matches = [];
            preg_match_all('/-?\d[\d\s,.()]+/u', $part, $matches);
            if (!isset($matches[0]) || [] === $matches[0]) {
                continue;
            }
            foreach ($matches[0] as $value) {
                $parsed = $this->parseAmountValue($value);
                if (abs($parsed) > 0.0) {
                    $values[] = $parsed;
                }
            }
        }

        return $values;
    }

    private function parseAmountValue(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float)$value;
        }
        if (!is_string($value)) {
            return 0.0;
        }

        $normalized = trim(str_replace(["\u{00A0}", ' '], ['', ''], $value));
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

    private function extractIban(string $text): string
    {
        $match = [];
        if (preg_match('/\b[A-Z]{2}\d{2}[A-Z0-9]{8,}/i', $text, $match)) {
            return strtoupper(trim($match[0]));
        }

        return '';
    }

    private function extractCurrency(string $text): ?string
    {
        $match = [];
        if (preg_match('/\b(AED|ARS|AUD|AZN|BGN|BRL|BSD|CAD|CHF|CLP|CNY|COP|CRC|CZK|DKK|DOP|DZD|EGP|EUR|GBP|GEL|HKD|HRK|HUF|IDR|ILS|INR|JPY|KGS|KZT|MDL|MXN|NOK|PEN|PHP|PKR|PLN|RON|RSD|RUB|SEK|SGD|THB|TRY|UAH|USD|UZS|VND|ZAR)\b/i', $text, $match)) {
            return strtoupper(trim((string)$match[0]));
        }

        return null;
    }

    private function firstArrayString(mixed $value): ?string
    {
        if (!is_array($value) || [] === $value) {
            return null;
        }

        foreach ($value as $item) {
            if (is_string($item) && '' !== trim($item)) {
                return trim($item);
            }
        }

        return null;
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

    private function requestBalancePage(array $cookies): string
    {
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
                'GET',
                self::BALANCE_PAGE_PATH,
                [
                    'headers'         => [
                        'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                        'Cookie'     => $this->buildCookieHeader($cookies),
                        'User-Agent' => sprintf('FF3-data-importer/%s (%s)', config('importer.version'), config('importer.line_b')),
                    ],
                    'allow_redirects' => false,
                ]
            );
        } catch (TransferException $e) {
            $httpException             = new ImporterHttpException(sprintf('BasisBank Balance.aspx request failed: %s', $e->getMessage()), 0, $e);
            $httpException->statusCode = method_exists($e, 'getResponse') && null !== $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
            throw $httpException;
        }

        $status = (int)$response->getStatusCode();
        $this->updateSessionCookiesFromResponse($response);
        $location = trim((string)$response->getHeaderLine('Location'));
        if (302 === $status) {
            $locationInfo = '' === $location ? '[empty Location header]' : $location;
            logger()->warning(sprintf('BasisBank balance-page redirect detected: HTTP 302 Location="%s".', $locationInfo));
            if (str_contains(strtolower($location), 'login.aspx')) {
                $message                   = sprintf('BasisBank web session is not authorized while opening balance page. Redirect Location: %s', $locationInfo);
                $httpException             = new ImporterHttpException($message);
                $httpException->statusCode = $status;
                throw $httpException;
            }
            if ('' !== $location) {
                try {
                    $redirectResponse = $client->request(
                        'GET',
                        $location,
                        [
                            'headers'         => [
                                'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                                'Cookie'     => $this->buildCookieHeader($this->getSessionCookies()),
                                'Referer'    => sprintf('%s%s', self::BASE_WEB_URL, self::BALANCE_PAGE_PATH),
                                'User-Agent' => sprintf('FF3-data-importer/%s (%s)', config('importer.version'), config('importer.line_b')),
                            ],
                            'allow_redirects' => true,
                        ]
                    );
                } catch (TransferException $e) {
                    $httpException             = new ImporterHttpException(sprintf('BasisBank balance redirect follow-up failed: %s', $e->getMessage()), 0, $e);
                    $httpException->statusCode = method_exists($e, 'getResponse') && null !== $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
                    throw $httpException;
                }

                $redirectStatus = (int)$redirectResponse->getStatusCode();
                $this->updateSessionCookiesFromResponse($redirectResponse);
                if (401 === $redirectStatus || 403 === $redirectStatus || 440 === $redirectStatus) {
                    $httpException             = new ImporterHttpException(sprintf('BasisBank redirected balance page denied access (%d).', $redirectStatus));
                    $httpException->statusCode = $redirectStatus;
                    throw $httpException;
                }
                if ($redirectStatus >= 300) {
                    $httpException             = new ImporterHttpException(sprintf('BasisBank redirected balance page returned HTTP %d.', $redirectStatus));
                    $httpException->statusCode = $redirectStatus;
                    throw $httpException;
                }

                $redirectHtml = (string)$redirectResponse->getBody();
                if ($this->containsLoginForm($redirectHtml)) {
                    throw new ImporterHttpException('BasisBank redirected balance page returned login form, session is not authorized.');
                }

                return $redirectHtml;
            }
            $message                   = sprintf('BasisBank balance page returned HTTP 302. Redirect Location: %s', $locationInfo);
            $httpException             = new ImporterHttpException($message);
            $httpException->statusCode = $status;
            throw $httpException;
        }
        if (401 === $status || 403 === $status || 440 === $status) {
            $httpException             = new ImporterHttpException(sprintf('BasisBank web session denied access (%d).', $status));
            $httpException->statusCode = $status;
            throw $httpException;
        }
        if ($status >= 300) {
            $httpException             = new ImporterHttpException(sprintf('BasisBank balance page returned HTTP %d.', $status));
            $httpException->statusCode = $status;
            throw $httpException;
        }

        $html = (string)$response->getBody();
        if ($this->containsLoginForm($html)) {
            throw new ImporterHttpException('BasisBank balance page returned login form, session is not authorized.');
        }

        return $html;
    }

    private function callCardModule(string $funq, array $form): array
    {
        $cookies = $this->getSessionCookies();
        if ([] === $cookies) {
            throw new ImporterHttpException('BasisBank web session is not available for card-module account retrieval.');
        }
        $client  = new Client(
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
                        'Accept'          => 'application/json, text/javascript, */*; q=0.01',
                        'Content-Type'    => 'application/x-www-form-urlencoded; charset=UTF-8',
                        'Cookie'          => $this->buildCookieHeader($cookies),
                        'Referer'         => sprintf('%s/Products/Cards/Default.aspx', self::BASE_WEB_URL),
                        'User-Agent'      => sprintf('FF3-data-importer/%s (%s)', config('importer.version'), config('importer.line_b')),
                        'X-Requested-With' => 'XMLHttpRequest',
                    ],
                    'form_params' => $form,
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
                $httpException             = new ImporterHttpException(sprintf('BasisBank CardModule authentication required ("%s") after redirect follow-up.', $funq));
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
                throw new ImporterHttpException(sprintf('BasisBank CardModule returned login form while requesting "%s" (redirect follow-up).', $funq));
            }
            if ('' === $redirectBody) {
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
            $httpException             = new ImporterHttpException(sprintf('BasisBank CardModule authentication required ("%s").', $funq));
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
            throw new ImporterHttpException(sprintf('BasisBank CardModule returned login form while requesting "%s".', $funq));
        }
        if ('' === $body) {
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
        $login    = trim(SecretManager::getLogin());
        $password = trim(SecretManager::getPassword());
        if ('' === $login || '' === $password) {
            throw $trigger;
        }

        logger()->warning(
            sprintf(
                'BasisBank CardModule auth recovery triggered for "%s" after status %d: %s',
                $funq,
                (int)$trigger->statusCode,
                $trigger->getMessage()
            )
        );

        try {
            $client = new BasisBankWebAuthClient();
            $state  = $client->start(
                $login,
                $password,
                SecretManager::getRequestSmsCode(),
                SecretManager::getTrustDevice(),
                $this->resolveSessionArtifact()
            );
        } catch (ImporterErrorException $e) {
            $httpException             = new ImporterHttpException(sprintf('BasisBank session recovery failed for "%s": %s', $funq, $e->getMessage()), 0, $e);
            $httpException->statusCode = (int)$trigger->statusCode;
            throw $httpException;
        }

        $authState = '' !== trim($state->getAuthState()) ? $state->getAuthState() : $state->getStatus();
        SecretManager::saveAuthState($authState);
        SecretManager::saveSessionArtifact($state->getSessionArtifact());

        if (!$state->isAuthenticated()) {
            $message = trim($state->getErrorMessage());
            if ('' === $message) {
                $message = sprintf('BasisBank authentication requires additional user action (%s).', strtolower($state->getStatus()));
            }
            $httpException             = new ImporterHttpException(sprintf('BasisBank session recovery requires re-authentication for "%s": %s', $funq, $message));
            $httpException->statusCode = (int)$trigger->statusCode;
            throw $httpException;
        }

        $this->sessionCookies       = $this->decodeArtifact($state->getSessionArtifact());
        $this->sessionCookiesLoaded = true;

        logger()->warning(sprintf('BasisBank CardModule auth recovery succeeded for "%s"; retrying request.', $funq));
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

    private function extractArrayPayload(mixed $payload): array
    {
        if (is_array($payload) && array_is_list($payload)) {
            return $payload;
        }
        if (!is_array($payload)) {
            return [];
        }

        foreach (['d', 'data', 'Data', 'result', 'Result', 'items', 'Items', 'List'] as $rootKey) {
            if (!isset($payload[$rootKey])) {
                continue;
            }
            $nested = $this->coercePayload($payload[$rootKey]);
            if (is_array($nested) && array_is_list($nested)) {
                return $nested;
            }
            if (is_array($nested)) {
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

    private function containsLoginForm(string $html): bool
    {
        return str_contains($html, 'id="UTXT"') && str_contains($html, 'id="PTXT"');
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
        if (isset($payload['accounts']) && is_array($payload['accounts'])) {
            return $payload['accounts'];
        }
        if (isset($payload['accountList']) && is_array($payload['accountList'])) {
            return $payload['accountList'];
        }
        if (array_is_list($payload)) {
            return $payload;
        }

        return [];
    }

    private function normalizeAccount(array $row): ?array
    {
        $id = trim((string)($row['resourceId'] ?? $row['accountId'] ?? $row['id'] ?? $row['iban'] ?? $row['accountNumber'] ?? ''));
        if ('' === $id) {
            return null;
        }

        $currency = (string)($row['currency'] ?? $row['currencyCode'] ?? '');
        if ('' === $currency && isset($row['balances']) && is_array($row['balances']) && isset($row['balances'][0]['balanceAmount']['currency'])) {
            $currency = (string)$row['balances'][0]['balanceAmount']['currency'];
        }

        return [
            'id'               => $id,
            'name'             => (string)($row['name'] ?? $row['product'] ?? $row['maskedPan'] ?? $id),
            'institution_name' => 'BasisBank',
            'institution_logo' => '',
            'provider'         => 'basisbank',
            'iban'             => (string)($row['iban'] ?? ''),
            'bban'             => (string)($row['accountNumber'] ?? ''),
            'currency'         => CurrencyCode::normalizeOrEmpty($currency),
            'status'           => (string)($row['status'] ?? 'active'),
            'extra'            => [
                'IBAN'              => (string)($row['iban'] ?? ''),
                'BBAN'              => (string)($row['accountNumber'] ?? ''),
                'Currency'          => CurrencyCode::normalizeOrEmpty($currency),
                'Balance'           => null,
                'Available balance' => null,
                'Card account'      => 'no',
            ],
            self::SYNC_IDS_KEY => $this->uniqueSyncIds(
                [
                    (string)($row['iban'] ?? ''),
                    (string)($row['accountNumber'] ?? ''),
                    $id,
                ]
            ),
        ];
    }
}
