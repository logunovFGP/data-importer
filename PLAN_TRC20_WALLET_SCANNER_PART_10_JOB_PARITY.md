# TRC20 plan_part_10: Job/map parity wiring

Date: 2026-02-16
Owner: Codex
Status: in_progress
Depends on: PART 7, PART 8, PART 9

## Files to edit (minimal)
- `data-importer/app/Http/Controllers/Import/ConfigurationController.php`
- `data-importer/app/Jobs/ProcessImportSubmissionJob.php`
- `data-importer/app/Http/Controllers/Import/MapController.php`

## Files to read only (for pattern)
- `data-importer/app/Jobs/ProcessImportSubmissionJob.php` (basisbank/tbank cursor block)
- `data-importer/app/Http/Controllers/Import/MapController.php` (provider parity branches)
- `data-importer/app/Http/Controllers/Import/ConfigurationController.php` (provider list merge helper)


## Execution directive (context hygiene)
- Do not deep-scan or reopen additional files beyond this plan and the `Files to read only` list.
- Execute this part strictly using the listed `Files to edit` and this plan context to avoid context overflow.

- Ensure TRC20 is present in map/configure parity path with existing non-file provider behavior.
- Include TRC20 in submission completion and sync state handling to keep incremental state tracking stable.

## Context extracted from scanned files
- `ConfigurationController` merges provider account lists through shared helper functions and supports `basisbank`/`tbank`.
- `MapController` currently exposes provider mapping information for non-file providers using helper branches.
- `ProcessImportSubmissionJob` updates sync state based on parsed flow, including completion flags for existing providers.

## Exact implementation sequence
- Edit `ConfigurationController`:
  - include `trc20` in flow-agnostic merges where map payloads are passed through.
- Edit `MapController`:
  - ensure `getImporterMapInformation` and related helpers include TRC20 account arrays from `CollectsAccounts`.
- Edit `ProcessImportSubmissionJob`:
  - include `trc20` in submission completion/sync-state branch
  - preserve existing status codes for non-file providers and keep fallback behavior untouched.

## Progress
- [ ] Add TRC20 to non-file configure merge path (`data-importer/app/Http/Controllers/Import/ConfigurationController.php`). Status: implemented, awaiting user confirmation.
- [ ] Add TRC20 support in importer mapping branch selection (`data-importer/app/Http/Controllers/Import/MapController.php`). Status: implemented, awaiting user confirmation.
- [ ] Add TRC20 to import-submission sync-state and cursor fingerprint update path (`data-importer/app/Jobs/ProcessImportSubmissionJob.php`). Status: implemented, awaiting user confirmation.

