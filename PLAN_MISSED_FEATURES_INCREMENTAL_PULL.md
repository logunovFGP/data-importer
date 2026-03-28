# PLAN: Missed Features - Robust Pull UX, Live Auth Check, Incremental Sync

Date: 2026-02-13
Status: in_progress

## Scope
- Add live provider auth validation for `basisbank` and `tbank` (not token-presence-only).
- Add explicit "pull remote data" UX state/checklist in conversion step.
- Add opt-in incremental sync cursor support with clear user explanation.
- Preserve duplicate safety and existing import flow architecture.

## Constraints
- Reuse existing importer flow (`upload -> configure -> convert -> submit`) with minimal routing changes.
- Keep behavior backward-compatible when incremental mode is disabled.
- Do not update cursor on partial/failed imports.
- No git operations.

## Historical plan review
- Reviewed available archive metadata in `../firefly-iii/plans/archive/README.md`.
- No archived implementation plan for this importer feature set was found in this module.

## Decision Log
- Incremental mode is opt-in (checkbox in configure UI), not default.
- Cursor baseline is "last successful import completion", not user login timestamp.
- Cursor key includes provider + source account + Firefly context fingerprint to avoid collisions.
- Cursor is updated only after `submission_done`.
- A safety lookback window of 3 days is applied when cursor-derived date is used.
- Live auth checks call lightweight account/list endpoints via existing request clients.
- Conversion UI can show checklist/progress because conversion is currently one synchronous routine request.
- Incremental controls are rendered only for `basisbank` and `tbank` in the date/range configure partial to reduce UI surface area.
- Duplicate summary is exposed on submit polling responses through existing `SubmissionStatus` payload instead of adding new API endpoints.

## Tasks
- [ ] 1. Add incremental sync settings to configuration model and request parsing.
  - Add fields for `incremental_enabled` and optional `incremental_lookback_days`.
  - Wire request validation and serialization.
  - Touched files (planned): `app/Services/Shared/Configuration/Configuration.php`, `app/Http/Request/ConfigurationPostRequest.php`.
  - Status: implemented, awaiting user confirmation. Touched files: `app/Services/Shared/Configuration/Configuration.php`, `app/Http/Request/ConfigurationPostRequest.php`, `resources/views/v2/import/004-configure/partials/data-importer-dates.blade.php`.

- [ ] 2. Add configure-step UI controls and explanatory help for incremental mode.
  - Add opt-in checkbox and concise "what this does" text.
  - Keep defaults backward-compatible (disabled).
  - Touched files (planned): `resources/views/v2/import/004-configure/partials/data-importer-dates.blade.php`.
  - Status: implemented, awaiting user confirmation. Touched files: `resources/views/v2/import/004-configure/partials/data-importer-dates.blade.php`.

- [ ] 3. Implement persistent sync cursor service (decoupled storage).
  - Create shared service for read/write cursor state under `storage/`.
  - Define keying strategy and integrity checks.
  - Touched files (planned): `app/Services/Shared/SyncState/*`.
  - Status: implemented, awaiting user confirmation. Touched files: `app/Services/Shared/SyncState/SyncStateManager.php`.

- [ ] 4. Implement effective `dateFrom` resolution in BasisBank/TBank pull routines.
  - Respect explicit date-range overrides.
  - If incremental is enabled and no explicit earlier bound is forced, derive start from cursor minus lookback.
  - Touched files (planned): `app/Services/BasisBank/Conversion/Routine/TransactionProcessor.php`, `app/Services/TBank/Conversion/Routine/TransactionProcessor.php`.
  - Status: implemented, awaiting user confirmation. Touched files: `app/Services/Shared/SyncState/SyncStateManager.php`, `app/Services/BasisBank/Conversion/Routine/TransactionProcessor.php`, `app/Services/TBank/Conversion/Routine/TransactionProcessor.php`.
  - Non-trivial choice: incremental cursor is only applied when `date_not_before` is explicitly empty (`null` in resolved config path), not when it is blank string from upstream callers. This was fixed by null-safe check in transaction processors to avoid missing cursor resolution.

- [ ] 5. Implement live auth health checks for BasisBank/TBank.
  - Extend validators/controllers to return OK/NODATA/NOK based on real provider ping result.
  - Keep clear errors for token-expired/consent-invalid.
  - Touched files (planned): `app/Services/BasisBank/AuthenticationValidator.php`, `app/Services/TBank/AuthenticationValidator.php`, `app/Http/Controllers/ServiceController.php`, `app/Api/Controllers/ImportFlow/ValidationController.php`.
  - Status: implemented, awaiting user confirmation. Touched files: `app/Services/BasisBank/Authentication/Request/GetPingRequest.php`, `app/Services/TBank/Authentication/Request/GetPingRequest.php`, `app/Services/BasisBank/AuthenticationValidator.php`, `app/Services/TBank/AuthenticationValidator.php`.

- [ ] 6. Add conversion-phase checklist/progress model for "pull remote data".
  - Extend conversion status with pull phases.
  - Render checklist and state in conversion UI.
  - Touched files (planned): `app/Services/Shared/Conversion/ConversionStatus.php`, `resources/views/v2/import/007-convert/index.blade.php`, `resources/js/v2/src/pages/conversion/index.js`.
  - Status: implemented, awaiting user confirmation. Touched files: `app/Services/Shared/Conversion/ConversionStatus.php`, `app/Services/BasisBank/Conversion/RoutineManager.php`, `app/Services/TBank/Conversion/RoutineManager.php`, `resources/views/v2/import/007-convert/index.blade.php`, `resources/js/v2/src/pages/conversion/index.js`.

- [ ] 7. Update submission completion path to persist cursor only after success.
  - Commit cursor state when submission status reaches `submission_done`.
  - Do not advance cursor on errors/partial outcomes.
  - Touched files (planned): `app/Jobs/ProcessImportSubmissionJob.php`, `app/Services/Shared/Import/Routine/ApiSubmitter.php`.
  - Status: implemented, awaiting user confirmation. Touched files: `app/Jobs/ProcessImportSubmissionJob.php`, `app/Services/Shared/Import/Routine/ApiSubmitter.php`.

- [ ] 8. Improve duplicate transparency in status messages.
  - Report duplicate-skipped counts/messages consistently in submission output.
  - Keep identifier-based detection as default for `basisbank` and `tbank`.
  - Touched files (planned): `app/Services/Shared/Import/Routine/ApiSubmitter.php`.
  - Status: implemented, awaiting user confirmation. Touched files: `app/Services/Shared/Import/Status/SubmissionStatus.php`, `app/Services/Shared/Import/Routine/ApiSubmitter.php`, `resources/js/v2/src/pages/submit/index.js`, `resources/views/v2/import/008-submit/index.blade.php`.

- [ ] 9. Verification.
  - Run PHP syntax checks on touched files.
  - Build V2 assets.
  - Verify runtime paths (`/new-import/basisbank`, `/new-import/tbank`, conversion/submit progress UI) and provider validate endpoints.
  - Use Playwright to verify mounted elements and progress/checklist visibility.
  - Status: implemented, awaiting user confirmation. Touched checks: `php -l`, `npm --workspace resources/js/v2 run build`.

- [ ] 10. Documentation updates.
  - Document incremental mode semantics, automation value, cursor behavior, and failure semantics.
  - Touched files (planned): `readme.md`, `resources/schemas/v3.json`.
  - Status: implemented, awaiting user confirmation. Touched files: `readme.md`.
