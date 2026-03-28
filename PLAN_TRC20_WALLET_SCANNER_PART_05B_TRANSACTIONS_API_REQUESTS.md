# TRC20 plan_part_5B: Transactions API request + mapping

Date: 2026-02-16
Owner: Codex
Status: in_progress
Depends on: PART 5A

## Files to edit (minimal)
- `data-importer/app/Services/TRC20/Request/GetTransactionsRequest.php` (new)
- `data-importer/app/Services/TRC20/Response/GetTransactionsResponse.php` (new)

## Files to read only (for pattern)
- `data-importer/app/Services/TBank/Request/GetTransactionsRequest.php`
- `data-importer/app/Services/BasisBank/Request/GetTransactionsRequest.php`
- `data-importer/app/Services/BasisBank/Response/GetTransactionsResponse.php` if present


## Execution directive (context hygiene)
- Do not deep-scan or reopen additional files beyond this plan and the `Files to read only` list.
- Execute this part strictly using the listed `Files to edit` and this plan context to avoid context overflow.

## Implementation outcome (pending user confirmation)

- Status: implemented, awaiting user confirmation.
- Added `data-importer/app/Services/TRC20/Request/GetTransactionsRequest.php` with:
  - wallet-filtered input support (normalized lower-cased wallets)
  - `from`/`to` window query binding with timestamp/date compatibility
  - API-key header query pattern and endpoint from `trc20.transactions_endpoint`
  - page-size controlled batching and cursor propagation
  - USDT token filtering at row mapping boundary (symbol/contract checks)
  - signed amount normalization, account assignment by sender/receiver role, stable descending-date ordering
  - demo/fake-data response path and graceful empty response for no wallets
- Added `data-importer/app/Services/TRC20/Response/GetTransactionsResponse.php` with:
  - iterator/countable response over `Transaction`
  - `setNextCursor`/`getNextCursor`/`hasNextCursor`
  - token symbol / token contract helper checks requested for TRC20 pipeline

- Add TRC20 transaction puller with API key auth and wallet-filtered scope.
- Support paging (`from`/`to` style window, cursor or marker-based page iteration as configured).
- Enforce USDT-only filtering at request/response mapping boundary.
- Normalize response into importer-ready fields for processor pipeline.

## Context extracted from scanned files
- Both BasisBank and TBank transaction request classes already split request building, paging, and response parsing concerns.
- BasisBank/TBank transaction conversion in this repo expects a normalized shape even when endpoint payloads differ in field names.
- TRC20 requirement is strict token scope; avoid relying on later conversion to drop non-USDT rows.

## Exact implementation sequence
- Create `GetTransactionsRequest`:
  - input: wallets + `from`/`to` boundaries + pagination cursor from config/sync state
  - output: list of raw transaction payloads and next cursor where available
  - on non-USDT token records, either skip or mark filtered.
- Create `GetTransactionsResponse`:
  - map tx hash/id, timestamps, amount, sender, recipient, memo, fee fields to normalized keys
  - expose token symbol contract check helpers used by collector/conversion.
- Ensure methods return stable list ordering for deterministic duplicate handling in later stages.

