# TRC20 plan_part_8: Account helper mapping

Date: 2026-02-16
Owner: Codex
Status: in_progress
Depends on: PART 6

## Files to edit (minimal)
- `data-importer/app/Support/Internal/CollectsAccounts.php`

## Files to read only (for pattern)
- `data-importer/app/Support/Internal/CollectsAccounts.php` (basisbank/tbank helpers)


## Execution directive (context hygiene)
- Do not deep-scan or reopen additional files beyond this plan and the `Files to read only` list.
- Execute this part strictly using the listed `Files to edit` and this plan context to avoid context overflow.

- Add account normalization for TRC20 account objects returned by collector.
- Keep output structure consistent with existing account lists so map UI and importer configuration consume one common shape.

## Context extracted from scanned files
- `CollectsAccounts` is the shared normalization layer for non-file provider account maps.
- Existing methods handle provider-specific id/iban/label fields and normalize into internal account arrays used by map controller.
- TRC20 wallet objects must be reduced to this normalized structure before mapping step.

## Exact implementation sequence
- Update `CollectsAccounts` with a TRC20 account branch:
  - map wallet address to account identifier field used elsewhere
  - map display name/label from wallet metadata where available
  - keep numeric/string safety for downstream map keys.
- Ensure the branch returns the same array shape as basisbank/tbank accounts and never `null`.

## Implementation outcome (pending user confirmation)

- Status: implemented, awaiting user confirmation.
- Files touched:
  - `data-importer/app/Support/Internal/CollectsAccounts.php`
- Status notes:
  - Added `getTRC20Accounts(Configuration $configuration)` to resolve TRC20 wallets using `GetWalletsRequest`.
  - Added `normalizeTRC20ServiceAccount()` to normalize TRC20 wallet objects to importer-compatible account array shape (`id`, `name`, `currency_code`, `status`, `extra`, etc.).
  - Kept TRC20 method resilient when configured account list is empty and reused existing timeout configuration.

## Decision log

- Added normalization as a dedicated private helper to keep TRC20 account mapping shape explicit and avoid leaking API-specific keys (`token`, `provider`, etc.) into shared account flows.

