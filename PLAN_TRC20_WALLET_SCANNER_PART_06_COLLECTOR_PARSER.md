# TRC20 plan_part_6: Collector + job parser registration

Date: 2026-02-16
Owner: Codex
Status: in_progress
Depends on: PART 5B

## Files to edit (minimal)
- `data-importer/app/Services/TRC20/Validation/NewJobDataCollector.php` (new)
- `data-importer/app/Repository/ImportJob/ImportJobRepository.php`

## Files to read only (for pattern)
- `data-importer/app/Services/BasisBank/Validation/NewJobDataCollector.php`
- `data-importer/app/Services/TBank/Validation/NewJobDataCollector.php`
- `data-importer/app/Services/Shared/Validation/NewJobDataCollectorInterface.php`
- `data-importer/app/Repository/ImportJob/ImportJobRepository.php`


## Execution directive (context hygiene)
- Do not deep-scan or reopen additional files beyond this plan and the `Files to read only` list.
- Execute this part strictly using the listed `Files to edit` and this plan context to avoid context overflow.

- Add TRC20 collector service implementing shared collector contract.
- Register TRC20 branch in import-job parsing dispatch.
- Ensure parse/import jobs include TRC20 accounts and transaction sync state inputs.

## Implementation outcome (pending user confirmation)

- Status: implemented, awaiting user confirmation.
- Added `data-importer/app/Services/TRC20/Validation/NewJobDataCollector.php` implementing `NewJobDataCollectorInterface`:
  - flow name `trc20`
  - validates presence of `trc20_api_key` and at least one wallet
  - collects TRC20 accounts via `GetWalletsRequest`
  - writes collector-updated import job via `ImportJobRepository::saveToDisk`
  - preserves existing error pattern with `MessageBag` and `connection` error key
- Updated `data-importer/app/Repository/ImportJob/ImportJobRepository.php`:
  - added `TRC20NewJobDataCollector` import
  - added `case 'trc20':` branch in `parseImportJob()` mirroring other non-file providers and setting duplicate detection method to `cell`
- Added explicit timeout usage for TRC20 account collection.

## Context extracted from scanned files
- `NewJobDataCollectorInterface` is used across provider collector services and defines method set needed by `ImportSubmission`/repository wiring.
- BasisBank and TBank collectors split responsibilities: request fetch + account validation + transaction fetch and then configuration patching.
- `ImportJobRepository::parseImportJob()` currently has explicit branches for existing non-file providers and updates job data for their transaction flows.
- `ImportJob` type objects in repository parse path expect account list and config fields added in prior plan parts.

## Exact implementation sequence
- Create `TRC20/Validation/NewJobDataCollector`:
  - implements interface used by all providers.
- Implement account collection methods using TRC20 request classes from parts 5A/5B.
- Register `trc20` branch in `ImportJobRepository::parseImportJob()`:
  - instantiate the new collector
  - call account collection
  - merge TRC20 accounts into parsed job payload.
- Keep error handling and logging format in line with existing providers so failures are mapped identically.

