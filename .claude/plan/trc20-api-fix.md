# Plan: Fix TRC20 Import — Switch to TronGrid API

## Problem

TRC20 import returns 0 accounts and 0 transactions despite valid wallet address and API key. The implementation targets the Tronscan API but uses wrong parameters, wrong pagination, and wrong response parsing.

## Root Cause (from container logs)

```
TRC20 collectAccounts: SUCCESS — fetched 0 accounts
No transactions were downloaded from TRC20.
Conversion routine "trc20" yielded 0 transaction(s).
```

### Issues in Current Implementation

| Issue | Current (Wrong) | Correct (TronGrid) |
|-------|----------------|---------------------|
| Base URL | `https://apilist.tronscanapi.com/api/` | `https://api.trongrid.io` |
| TRC20 transactions | `GET /api/transfer?address=...` | `GET /v1/accounts/{address}/transactions/trc20` |
| Account info | `GET /api/account?address=...` | `GET /v1/accounts/{address}` |
| Pagination | Cursor-based (broken — always null) | Fingerprint: `meta.fingerprint` |
| Date filtering | `from`, `from_timestamp`, `start`, `to` (mixed) | `min_timestamp`, `max_timestamp` (milliseconds) |
| Auth header | `TRON-PRO-API-KEY` | `TRON-PRO-API-KEY` (same) |
| Token filter | `token=USDT` query param | `contract_address=TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t` |
| Response data | Multiple fallbacks (`data`, `transfers`, `token_transfers`) | Always `data[]` array |
| Response cursor | `nextCursor`, `cursor`, `meta.next_cursor` (guessed) | `meta.fingerprint` (documented) |

## Solution: Rewrite API Requests to Use TronGrid

### TronGrid API Reference

**TRC20 Transactions:** `GET /v1/accounts/{address}/transactions/trc20`

Query parameters:
| Parameter | Type | Description |
|-----------|------|-------------|
| `only_confirmed` | bool | Only confirmed txs (default: false) |
| `limit` | int | Page size, max 200 (default: 20) |
| `fingerprint` | string | Pagination token from previous `meta.fingerprint` |
| `order_by` | string | Sort: `block_timestamp,desc` or `block_timestamp,asc` |
| `min_timestamp` | long | Start time in milliseconds |
| `max_timestamp` | long | End time in milliseconds |
| `contract_address` | string | Token contract (USDT: `TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t`) |

Response:
```json
{
  "data": [
    {
      "transaction_id": "abc123...",
      "token_info": {
        "symbol": "USDT",
        "address": "TR7NHq...",
        "decimals": 6,
        "name": "Tether USD"
      },
      "block_timestamp": 1711234567000,
      "from": "T...",
      "to": "T...",
      "type": "Transfer",
      "value": "1000000"
    }
  ],
  "success": true,
  "meta": {
    "at": 1711234567000,
    "fingerprint": "...",
    "page_size": 200,
    "links": { "next": "..." }
  }
}
```

**Account Info:** `GET /v1/accounts/{address}`

Response includes: `data[0].address`, `data[0].balance` (TRX in sun), `data[0].trc20` (array of token balances), `data[0].create_time`.

### Rate Limits
- Without API key: Dynamic throttling
- With API key: 100,000 requests/day, 15 QPS
- Exceeding limit: HTTP 429 + 30-second block

## Implementation Steps

### Phase 1: Update Config (`config/trc20.php`)

**File:** `config/trc20.php` (MODIFY)

```php
return [
    'api_url'                => env('TRC20_API_URL', 'https://api.trongrid.io'),
    'transactions_endpoint'  => env('TRC20_TRANSACTIONS_ENDPOINT', '/v1/accounts/%s/transactions/trc20'),
    'wallets_endpoint'       => env('TRC20_WALLETS_ENDPOINT', '/v1/accounts/%s'),
    'usdt_contract_address'  => env('TRC20_USDT_CONTRACT', 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t'),
    'api_key'                => env('TRC20_API_KEY', ''),
    'wallets'                => env('TRC20_WALLETS', ''),
    'request_timeout'        => (int) env('TRC20_REQUEST_TIMEOUT', 30),
    'page_size'              => (int) env('TRC20_PAGE_SIZE', 200),
    'max_pages'              => (int) env('TRC20_MAX_PAGES', 100),
];
```

Key changes:
- Base URL → `api.trongrid.io`
- Endpoints use `%s` placeholder for address in path
- Add `usdt_contract_address` with mainnet USDT default
- Page size → 200 (TronGrid max)

### Phase 2: Rewrite `GetWalletRequest.php` / `GetWalletsRequest.php`

**Files:** `app/Services/TRC20/Request/GetWalletRequest.php`, `GetWalletsRequest.php` (MODIFY)

TronGrid account endpoint: `GET /v1/accounts/{address}`

Changes:
- Build URL with address in path: `$baseUrl . sprintf($endpoint, $address)`
- Remove query params (`token`, `limit`) — not needed for account info
- Parse response: `$data = $response['data'][0]` (always array with single element)
- Extract: `address`, `balance` (TRX), `create_time`, `trc20` array for token balances
- For USDT balance: find entry in `trc20` array where key matches USDT contract address

### Phase 3: Rewrite `GetTransactionsRequest.php`

**File:** `app/Services/TRC20/Request/GetTransactionsRequest.php` (MODIFY)

TronGrid TRC20 endpoint: `GET /v1/accounts/{address}/transactions/trc20`

Changes to `buildQuery()`:
```php
$query = [
    'only_confirmed'   => 'true',
    'limit'            => $this->pageSize,
    'order_by'         => 'block_timestamp,asc',
    'contract_address' => config('trc20.usdt_contract_address'),
];
if ($this->dateFrom) {
    $query['min_timestamp'] = $this->dateFrom->getTimestampMs();
}
if ($this->dateTo) {
    $query['max_timestamp'] = $this->dateTo->getTimestampMs();
}
if ($this->cursor) {
    $query['fingerprint'] = $this->cursor;
}
```

Changes to `buildUrl()`:
```php
// Address goes in the path, not query
$endpoint = sprintf(config('trc20.transactions_endpoint'), $this->address);
return rtrim(config('trc20.api_url'), '/') . $endpoint;
```

Changes to `extractRows()`:
```php
// TronGrid always returns data in 'data' array
return $payload['data'] ?? [];
```

Changes to `extractCursor()`:
```php
// TronGrid uses meta.fingerprint for pagination
return $payload['meta']['fingerprint'] ?? null;
```

Changes to `normalizeTransaction()`:
```php
// TronGrid field names are consistent:
$normalized = [
    'txID'        => $raw['transaction_id'],
    'from'        => $raw['from'],
    'to'          => $raw['to'],
    'amount'      => $raw['value'],       // In sun (divide by 10^decimals)
    'decimals'    => $raw['token_info']['decimals'] ?? 6,
    'timestamp'   => $raw['block_timestamp'],  // Milliseconds
    'symbol'      => $raw['token_info']['symbol'] ?? 'USDT',
    'contract'    => $raw['token_info']['address'] ?? '',
    'type'        => $raw['type'] ?? 'Transfer',
];
```

### Phase 4: Fix Pagination Loop

**File:** `GetTransactionsRequest.php` (same file, MODIFY)

Current code calls `extractCursor()` which always returns null from Tronscan → pagination stops after page 1.

With TronGrid:
```php
do {
    $response = $this->fetch($url, $query);
    $rows = $this->extractRows($response);
    $transactions = array_merge($transactions, $rows);

    $fingerprint = $response['meta']['fingerprint'] ?? null;
    if ($fingerprint === null || $fingerprint === $this->cursor) {
        break; // No more pages or stuck
    }
    $this->cursor = $fingerprint;
    $query['fingerprint'] = $fingerprint;
    $page++;
} while ($page < $this->maxPages && count($rows) >= $this->pageSize);
```

### Phase 5: Fix Amount Handling

**File:** `Conversion/Routine/TransactionProcessor.php` (MODIFY)

TronGrid returns `value` as string in sun (smallest unit). For USDT with 6 decimals:
```php
$amount = bcdiv($raw['value'], bcpow('10', (string) ($raw['token_info']['decimals'] ?? 6)), 8);
```

Current code has multiple fallback paths for amount extraction — simplify to use TronGrid's consistent `value` + `token_info.decimals`.

### Phase 6: Add Error Logging and User Feedback

**Files:** `GetTransactionsRequest.php`, `GetWalletsRequest.php`, `RoutineManager.php` (MODIFY)

Currently fails silently with "fetched 0 accounts". Add:
1. Log the actual HTTP response code and body when 0 results returned
2. Log the full request URL (with query params) for debugging
3. If API returns error JSON, extract and display the error message to user
4. Handle HTTP 429 (rate limit) with explicit user message
5. Show "API returned empty response" vs "No transactions match your filters" distinction

### Phase 7: Remove Dead Response Format Fallbacks

**File:** `GetTransactionsRequest.php` (MODIFY)

Remove the 6+ response format fallbacks (`data.transfers`, `token_transfers`, `transactions`, etc.) — TronGrid has a single documented format. Keep only `$payload['data']`.

Same for cursor: remove `nextCursor`, `cursor`, `meta.next_cursor`, `page_info.next` fallbacks — keep only `$payload['meta']['fingerprint']`.

## Key Files

| File | Operation | Description |
|------|-----------|-------------|
| `config/trc20.php` | MODIFY | Switch to TronGrid URL, add USDT contract |
| `app/Services/TRC20/Request/GetTransactionsRequest.php` | REWRITE | TronGrid endpoint, query params, pagination, response parsing |
| `app/Services/TRC20/Request/GetWalletRequest.php` | REWRITE | TronGrid account endpoint, address in path |
| `app/Services/TRC20/Request/GetWalletsRequest.php` | REWRITE | Same as GetWalletRequest but for multiple wallets |
| `app/Services/TRC20/Conversion/Routine/TransactionProcessor.php` | MODIFY | Fix amount parsing for TronGrid format |
| `app/Services/TRC20/Conversion/RoutineManager.php` | MODIFY | Better error logging and user feedback |
| `.env.example` | MODIFY | Update TRC20 env variable defaults |

## Risks

| Risk | Mitigation |
|------|------------|
| TronGrid rate limit (100K/day) | Log remaining quota, implement exponential backoff on 429 |
| Breaking existing user configs | Old Tronscan URLs still work if user overrides env vars |
| USDT contract address changes | Configurable via env var, default to mainnet USDT |
| Multiple wallets require per-wallet API calls | TronGrid requires address in path — loop over wallets |

## Checklist

- [ ] Phase 1: Update config to TronGrid defaults
- [ ] Phase 2: Rewrite wallet request for TronGrid `/v1/accounts/{address}`
- [ ] Phase 3: Rewrite transactions request for TronGrid `/v1/accounts/{address}/transactions/trc20`
- [ ] Phase 4: Fix pagination to use `meta.fingerprint`
- [ ] Phase 5: Fix amount parsing (sun → USDT with decimals)
- [ ] Phase 6: Add error logging and user-facing error messages
- [ ] Phase 7: Remove dead response format fallbacks
- [ ] Verify: Run import with real wallet and confirm transactions are fetched
