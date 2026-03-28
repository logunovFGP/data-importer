# TRC20 plan_part_5A: Wallets API request + mapping

Date: 2026-02-16
Owner: Codex
Status: in_progress
Depends on: PART 4

## Files to edit (minimal)
- `data-importer/app/Services/TRC20/Request/GetWalletRequest.php` (new)
- `data-importer/app/Services/TRC20/Request/GetWalletsRequest.php` (new)

## Files to read only (for pattern)
- `data-importer/app/Services/BasisBank/Request/GetAccountsRequest.php`
- `data-importer/app/Services/TBank/Request/GetAccountsRequest.php`


## Execution directive (context hygiene)
- Do not deep-scan or reopen additional files beyond this plan and the `Files to read only` list.
- Execute this part strictly using the listed `Files to edit` and this plan context to avoid context overflow.

- Add authenticated request class for single wallet details when a direct wallet hit is needed.
- Add paginated/batched wallet-list request using TRC20 config endpoints and API key auth.
- Normalize wallet payload output to the shape expected by TRC20 collector in Part 6.

## Context extracted from scanned files
- `BasisBank/Request/GetAccountsRequest.php` already implements endpoint + pagination + response normalization in provider style.
- `TBank/Request/GetAccountsRequest.php` shows the request/loop shape for token providers that do not reuse file import paths.
- TRC20 should only support wallet-based collection for USDT transaction discovery and should not require account metadata beyond address/name.

## Exact implementation sequence
- Add `GetWalletRequest` class:
  - accepts wallet identifier from flow config/collector
  - calls TRON endpoint through configured base URL and API key header/query
  - handles null/missing wallet ID and malformed responses with graceful error return.
- Add `GetWalletsRequest` class:
  - accepts wallets filter and optional pagination cursor/query params
  - repeats per page until configured max-page condition
  - returns an array list in stable account-like shape consumed by `NewJobDataCollector`.
- Keep API calls strictly USDT-targeted in request layer flags so downstream parser only receives expected token types.

## Implementation outcome (pending user confirmation)

- Status: implemented, awaiting user confirmation.
- Implementation notes:
  - Added pagination guard in `GetWalletsRequest::get()` (bounded `for` loop using `max_pages`) to prevent repeated request loops when cursor is absent.
  - Added request timeout wiring from `importer.connection.timeout` for multi-wallet retrieval path.
  - `GetWalletRequest` now normalizes wallet addresses to lowercase and hard-fails locally when address shape does not match TRC-20 supported wallet formats.
- Added TRC20 wallet request classes:
  - `data-importer/app/Services/TRC20/Request/GetWalletRequest.php`
  - `data-importer/app/Services/TRC20/Request/GetWalletsRequest.php`
- Implemented request-level behavior:
  - API base URL + API-key usage from `config('trc20.*')` including pagination settings.
  - Single-wallet fetch with graceful empty return for missing/invalid wallet identifiers.
  - Multi-wallet fetch with:
    - normalized wallet input list,
    - paginated/batched query loop with optional cursor support,
    - fallback per-wallet fetch when batched lookup cannot fully resolve all wallets.
  - Stable account-like output shape (id/name/institution/provider/currency/status) for collector consumption.
- Added explicit USDT token targeting in query/filter logic via request-level token constant.

