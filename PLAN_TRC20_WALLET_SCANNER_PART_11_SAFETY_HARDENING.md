# TRC20 plan_part_11: Safety and edge-case hardening

Date: 2026-02-16
Owner: Codex
Status: in_progress
Depends on: PART 6, PART 7

## Files to edit (minimal)
- `data-importer/app/Services/TRC20/Request/GetWalletsRequest.php`
- `data-importer/app/Services/TRC20/Request/GetTransactionsRequest.php`
- `data-importer/app/Services/TRC20/Validation/NewJobDataCollector.php`
- `data-importer/app/Services/TRC20/Conversion/Routine/TransactionProcessor.php`

## Files to read only (for pattern)
- `data-importer/app/Services/TBank/Validation/NewJobDataCollector.php`
- `data-importer/app/Services/TBank/Conversion/Routine/TransactionProcessor.php`
- `data-importer/app/Services/TBank/Request/GetTransactionsRequest.php`


## Execution directive (context hygiene)
- Do not deep-scan or reopen additional files beyond this plan and the `Files to read only` list.
- Execute this part strictly using the listed `Files to edit` and this plan context to avoid context overflow.

- Add strict validation and parse-time guards for wallet identifiers.
- Reject malformed transaction payloads before processor emits output.
- Ensure request pagination terminates on empty datasets or explicit stop conditions.
- Keep deterministic duplicate key generation and resilient external ID fallback.

## Context extracted from scanned files
- TBank/BasisBank safety patterns already include null checks, graceful catches, and pagination stop conditions.
- Non-USDT filtering at boundary is mandatory and should be enforced before conversion for duplicate-key consistency.
- Existing parsers use exception-safe normalization so malformed rows do not crash full runs.

## Progress
- [ ] Add wallet-format validation in `GetWalletsRequest` before API calls and reject invalid entries with contextual error. Status: implemented, awaiting user confirmation. Touched file: `data-importer/app/Services/TRC20/Request/GetWalletsRequest.php`.
- [ ] Harden `GetTransactionsRequest` pagination/row validation to terminate on empty dataset, invalid page sizing, malformed timestamps, missing identifiers, and non-USDT rows with deterministic fallback IDs. Status: implemented, awaiting user confirmation. Touched file: `data-importer/app/Services/TRC20/Request/GetTransactionsRequest.php`.
- [ ] Harden `NewJobDataCollector` validation for API key and wallet formats with explicit error feedback before attempting discovery calls. Status: implemented, awaiting user confirmation. Touched file: `data-importer/app/Services/TRC20/Validation/NewJobDataCollector.php` (wallet patterns now include TRON `41...` / `0x`-prefixed formats and lowercase normalization before validation).
- [ ] Harden `TransactionProcessor` conversion pipeline checks for valid tx id/date, USDT symbol/contract filtering, and duplicate-safe deterministic id fallback. Status: implemented, awaiting user confirmation. Touched file: `data-importer/app/Services/TRC20/Conversion/Routine/TransactionProcessor.php`.

## Exact implementation sequence
- In `GetWalletsRequest`, validate wallet string format and reject empty/invalid entries before API calls.
- In `GetTransactionsRequest`, stop paging immediately when:
  - no page data is returned
  - `limit` or `page_size` reaches zero
  - response cursor is missing and no new rows exist.
- In `NewJobDataCollector`, fail fast with context if config wallets/API key are invalid and keep a clear error message.
- In `TransactionProcessor`, add defensive parsing checks:
  - require non-empty tx id/hash
  - require valid timestamp
  - require USDT token match
  - keep deterministic key fallback if index is missing.

