# TRC20 plan_part_7: Conversion pipeline

Date: 2026-02-16
Owner: Codex
Status: in_progress
Depends on: PART 6

## Files to edit (minimal)
- `data-importer/app/Services/TRC20/Conversion/RoutineManager.php` (new)
- `data-importer/app/Services/TRC20/Conversion/Routine/TransactionProcessor.php` (new)
- `data-importer/app/Services/Shared/Conversion/ConversionRoutineFactory.php`

## Files to read only (for pattern)
- `data-importer/app/Services/BasisBank/Conversion/RoutineManager.php`
- `data-importer/app/Services/TBank/Conversion/RoutineManager.php`
- `data-importer/app/Services/TBank/Conversion/Routine/TransactionProcessor.php`
- `data-importer/app/Services/BasisBank/Conversion/Routine/TransactionProcessor.php`


## Execution directive (context hygiene)
- Do not deep-scan or reopen additional files beyond this plan and the `Files to read only` list.
- Execute this part strictly using the listed `Files to edit` and this plan context to avoid context overflow.

- Add TRC20 routine manager and register it in shared conversion factory.
- Implement transaction processor with deterministic sign, counterpart mapping, memo propagation, and USDT-only guard.
- Ensure duplicate prevention and external ID construction are stable across pages.

## Context extracted from scanned files
- `ConversionRoutineFactory` currently dispatches routine manager by flow and requires a dedicated branch for `trc20`.
- BasisBank and TBank routine managers are responsible for cursor input/output and transaction preprocessing.
- Both existing processor classes implement direction/amount and metadata extraction in one place, then return Firefly-compatible transaction objects.
- TRC20 scope is one token only, so conversion can safely short-circuit non-matching contracts in this stage.

## Exact implementation sequence
- Add `app/Services/TRC20/Conversion/RoutineManager.php`:
  - resolve TRC20-specific request classes
  - call transaction retrieval and pass each normalized row to processor.
- Add `app/Services/TRC20/Conversion/Routine/TransactionProcessor.php`:
  - reject non-USDT symbol/contract rows
  - infer direction from sender/recipient vs configured wallet addresses
  - map memo/fee/internal notes and amount sign.
- Update `ConversionRoutineFactory` with `trc20` branch pointing to new routine manager.

## Implementation outcome (pending user confirmation)

- Status: implemented, awaiting user confirmation.
- Files touched:
  - `data-importer/app/Services/TRC20/Conversion/Routine/TransactionProcessor.php`
  - `data-importer/app/Services/Shared/Conversion/ConversionRoutineFactory.php`
- Status notes:
  - `data-importer/app/Services/TRC20/Conversion/Routine/TransactionProcessor.php` was rewritten to:
    - normalize wallet scope explicitly,
    - enforce USDT-only filtering,
    - enforce deterministic sign and counterparty derivation by address role,
    - deduplicate by stable external-id signature and keep cursor/date boundaries,
    - parse and normalize memo/description safely without undefined nested key access.
  - `data-importer/app/Services/Shared/Conversion/ConversionRoutineFactory.php` now dispatches `TRC20RoutineManager` for flow `trc20`.

## Decision log

- Kept conversion-specific filtering in TRC20 processor to guard against future token/shape drift from upstream request implementations while still using shared request classes for paging and status handling.

