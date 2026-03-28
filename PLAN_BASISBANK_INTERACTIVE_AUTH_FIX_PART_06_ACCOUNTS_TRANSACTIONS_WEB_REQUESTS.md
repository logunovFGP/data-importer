# BasisBank plan_part_06: Accounts and Transactions Web Requests

Date: 2026-02-16
Owner: Codex
Status: planning
Depends on: PART 03, PART 05

## Files to edit (minimal)
- `data-importer/app/Services/BasisBank/Request/GetAccountsRequest.php`
- `data-importer/app/Services/BasisBank/Request/GetTransactionsRequest.php`
- `data-importer/app/Services/BasisBank/Request/GetPingRequest.php`
- `data-importer/app/Support/Internal/CollectsAccounts.php`

## Files to read only (for pattern)
- `BasisBank API/ZenPlugins/src/plugins/basisbank/fetchApi.ts`
- `data-importer/app/Services/BasisBank/Conversion/Routine/TransactionProcessor.php`

## Execution directive (context hygiene)
- Do not deep-scan or reopen additional files beyond this plan and the listed files.
- Execute this part strictly using this plan context to avoid context overflow.

## Objective
- Switch BasisBank retrieval path to authenticated web-session requests while preserving importer DTO contracts.

## Exact implementation scope
- Use persisted auth session artifact in BasisBank request classes.
- Retrieve and normalize accounts from web flow endpoints.
- Retrieve and normalize transactions from web flow endpoints.
- Keep normalized account/transaction shape compatible with existing conversion pipeline.

## Decision Log
- Used web-session flow when session artifact exists; API path is retained as fallback.
- Implemented account parsing for `/Balance.aspx` and `/Handlers/CardModule.ashx` with shared cookie-driven session handling.
- Transaction matching in web mode uses session token plus configured account filtering against `AccountIban` to avoid cross-account bleed.

## Verification target
- BasisBank account and transaction loading works without `basisbank_api_token`/`basisbank_consent_id`.

## Progress
- [ ] Implement BasisBank web-session account/transaction request layer and account collector integration. Status: implemented, awaiting user confirmation
  - Touched files:
    - `data-importer/app/Services/BasisBank/Request/GetAccountsRequest.php`
    - `data-importer/app/Services/BasisBank/Request/GetTransactionsRequest.php`
    - `data-importer/app/Support/Internal/CollectsAccounts.php`
    - `data-importer/app/Services/BasisBank/Request/GetPingRequest.php`

## Decision Log
- CHO #1: Kept API paths as fallback only when no web session artifact is available, and explicitly fail fast when neither artifact nor API credentials are present to avoid ambiguous session-expired behavior.
- CHO #2: Added account-list enrichment from card transaction pages (`getlasttransactionlist`) so accounts visible in transactions but absent from balance/card-list endpoints are still surfaced during mapping.
