# Plan: TRC20 Multi-Asset Wallet Support

## Problem

A TRON wallet is like a bank account that holds multiple assets simultaneously:
- **TRX** (native token) — balance: 197.02 TRX
- **USDT** (TRC20 token) — balance: 6558.54 USDT
- **Other TRC20 tokens** — various contracts with different decimals
- **TRC10 assets** — legacy token standard (19 assets on test wallet)

The current implementation is USDT-only: it creates one Firefly III account per wallet with currency "USDT" and filters out all non-USDT transactions. This means:
1. TRX transfers are lost
2. Other TRC20 tokens are lost
3. All assets are mixed into one account regardless of type

## Architecture: One Firefly III Account Per Token Per Wallet

### Concept

For wallet `TT65LR...Mhd`, create:
- `TT65LR...Mhd [TRX]` — Firefly III asset account, currency: TRX
- `TT65LR...Mhd [USDT]` — Firefly III asset account, currency: USDT
- `TT65LR...Mhd [WTRX]` — Firefly III asset account, currency: WTRX (if present)

Each account has its own currency. Transactions are routed to the correct account based on the token in the transaction.

### Currency Creation

Firefly III has a full currency API (`POST /v1/currencies`):
```json
{
  "name": "Tether USD",
  "code": "USDT",
  "symbol": "USDT",
  "decimal_places": 6,
  "enabled": true
}
```

The data-importer already has `PostCurrencyRequest` and auto-creates missing currencies during submission (`ApiSubmitter::createCurrency()`). So currencies will be created automatically when transactions are submitted — no special handling needed.

### TronGrid API Endpoints

**TRC20 transactions** (already implemented):
`GET /v1/accounts/{address}/transactions/trc20`
- Returns all TRC20 token transfers (USDT, WTRX, etc.)
- Each has `token_info.symbol`, `token_info.decimals`, `token_info.address`

**Native TRX transactions** (new, needs implementation):
`GET /v1/accounts/{address}/transactions`
- Returns native TRX transfers (`TransferContract`)
- Amount in `raw_data.contract[0].parameter.value.amount` (sun, 6 decimals)
- Also returns smart contract calls (`TriggerSmartContract`) — these overlap with TRC20 endpoint

**Account balances** (already implemented):
`GET /v1/accounts/{address}`
- `balance` — TRX in sun
- `trc20[]` — array of `{contract_address: balance}` entries
- `assetV2[]` — TRC10 tokens (legacy, can skip initially)

## Implementation Steps

### Phase 1: Remove USDT-Only Filter

**File:** `config/trc20.php` (MODIFY)
- Remove `usdt_contract_address` config (no longer filtering by single token)
- Add `supported_tokens` config: `env('TRC20_TOKENS', 'TRX,USDT')` — comma-separated list of tokens to import (default: TRX and USDT)

**File:** `TRC20TokenFilter.php` (MODIFY)
- Replace `isUSDT()` with `isSupported()` that checks against `supported_tokens` config list
- If list is empty or `*`, accept all tokens

**File:** `TRC20Constants.php` (MODIFY)
- Remove `CURRENCY_USDT` constant (no longer single-currency)
- Add `CURRENCY_TRX = 'TRX'` and `TRX_DECIMALS = 6`

### Phase 2: Expand GetWalletRequest to Return Per-Token Accounts

**File:** `GetWalletRequest.php` (MODIFY)
- Parse the full TronGrid account response
- For each TRC20 token in `trc20[]` array, create a separate service account entry
- Also create one for native TRX balance
- Return array of accounts instead of single account

Output format per wallet:
```php
[
    [
        'id'            => 'TT65LR...Mhd|TRX',
        'name'          => 'TT65LR...Mhd [TRX]',
        'currency_code' => 'TRX',
        'currency_name' => 'TRON',
        'decimals'      => 6,
        'balance'       => '197.022100',
        'provider'      => 'trc20',
        'wallet'        => 'TT65LR...Mhd',
    ],
    [
        'id'            => 'TT65LR...Mhd|USDT',
        'name'          => 'TT65LR...Mhd [USDT]',
        'currency_code' => 'USDT',
        'currency_name' => 'Tether USD',
        'decimals'      => 6,
        'balance'       => '6558.542807',
        'provider'      => 'trc20',
        'wallet'        => 'TT65LR...Mhd',
    ],
]
```

**Challenge:** TronGrid's `/v1/accounts/{address}` returns TRC20 balances as `{contract_address: balance_in_sun}` but does NOT include the token symbol or name. We need to resolve contract → symbol mapping.

**Solution:** Call `GET /v1/accounts/{address}/transactions/trc20?limit=1&contract_address={contract}` for each unknown contract to get `token_info.symbol` and `token_info.decimals`. Cache the mapping.

**Alternative (simpler):** Only create accounts for tokens that appear in the transaction history. Fetch all TRC20 transactions first, collect unique `token_info` entries, then create accounts from that.

### Phase 3: Add Native TRX Transaction Fetching

**File:** `GetTransactionsRequest.php` (MODIFY)
- Add a new method `getTrxTransactions()` that queries `/v1/accounts/{address}/transactions`
- Filter for `TransferContract` type only (native TRX transfers)
- Normalize to the same output format as TRC20 transactions
- Amount field: `raw_data.contract[0].parameter.value.amount` (sun, 6 decimals)
- From/To: `raw_data.contract[0].parameter.value.owner_address` / `to_address`

**OR** create a separate `GetTrxTransactionsRequest.php` (cleaner separation).

### Phase 4: Route Transactions to Correct Accounts

**File:** `TransactionProcessor.php` (MODIFY)
- Currently maps `wallet → fireflyAccountId`
- Change to map `wallet|token_symbol → fireflyAccountId`
- When processing a transaction, look up the token symbol and route to the corresponding account
- Transaction's `currency_code` must match the account's currency

Key change in `normalizeTransactionRow()`:
```php
// Before: $accountId = $wallet
// After:  $accountId = sprintf('%s|%s', $wallet, $tokenSymbol)
```

### Phase 5: Update Account Mapping UI

**File:** `resources/views/v2/import/004-configure/` (CHECK)
- The configure step shows service accounts for the user to map to Firefly III accounts
- With per-token accounts, the user will see `TT65LR...Mhd [TRX]` and `TT65LR...Mhd [USDT]` as separate items to map
- This should work with the existing UI if the service accounts have unique IDs

### Phase 6: Currency Auto-Creation

**Already handled.** The `ApiSubmitter::createCurrency()` method automatically creates missing currencies during transaction submission. When a transaction has `currency_code: TRX` and TRX doesn't exist in Firefly III, it will be auto-created as:
```json
{
    "name": "Currency TRX",
    "code": "TRX",
    "symbol": "TRX",
    "decimal_places": 6,
    "enabled": true
}
```

Improvement: Use token metadata from TronGrid (`token_info.name`, `token_info.decimals`) for better currency names:
```json
{
    "name": "Tether USD",
    "code": "USDT",
    "symbol": "USDT",
    "decimal_places": 6
}
```

## Key Files

| File | Operation | Description |
|------|-----------|-------------|
| `config/trc20.php` | MODIFY | Add `supported_tokens`, remove `usdt_contract_address` |
| `Support/TRC20Constants.php` | MODIFY | Add TRX constant, remove USDT-only constant |
| `Support/TRC20TokenFilter.php` | MODIFY | `isUSDT()` → `isSupported()` with configurable token list |
| `Request/GetWalletRequest.php` | MODIFY | Return per-token accounts from wallet balance data |
| `Request/GetWalletsRequest.php` | MODIFY | Aggregate per-token accounts across wallets |
| `Request/GetTransactionsRequest.php` | MODIFY | Remove USDT-only filter, add token symbol to normalized output |
| `Request/GetTrxTransactionsRequest.php` | NEW | Native TRX transaction fetcher |
| `Conversion/Routine/TransactionProcessor.php` | MODIFY | Route transactions to per-token accounts |

## Scope Decision: TRC10 Assets

The test wallet has 19 TRC10 assets (legacy TRON token standard). These are mostly spam/airdrop tokens. **Recommendation:** Skip TRC10 initially. Focus on:
1. Native TRX (most common)
2. TRC20 tokens (USDT, WTRX, etc.)

TRC10 can be added later if needed.

## Risks

| Risk | Mitigation |
|------|------------|
| TronGrid rate limit with per-token queries | Batch account resolution, cache contract→symbol mapping |
| User confusion with many auto-created accounts | Clear naming: `{wallet_short} [{SYMBOL}]` |
| Currency code conflicts (e.g., "TRX" might exist with different decimals) | Check existing currency before creating, update decimals if needed |
| Large number of spam TRC20 tokens on wallet | `supported_tokens` config to whitelist only desired tokens |
| Native TRX transactions use different API format | Separate request class with dedicated normalization |

## Checklist

- [ ] Phase 1: Remove USDT-only filter, add configurable token list
- [ ] Phase 2: GetWalletRequest returns per-token accounts with balances
- [ ] Phase 3: Add native TRX transaction fetching
- [ ] Phase 4: Route transactions to correct per-token accounts
- [ ] Phase 5: Verify account mapping UI works with per-token accounts
- [ ] Phase 6: Verify currency auto-creation uses proper token metadata
