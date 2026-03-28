# BasisBank plan_part_07: Import Pipeline Integration and Fallback

Date: 2026-02-16
Owner: Codex
Status: planning
Depends on: PART 02, PART 06

## Files to edit (minimal)
- `data-importer/app/Services/BasisBank/Validation/NewJobDataCollector.php`
- `data-importer/app/Jobs/ProcessImportSubmissionJob.php`
- `data-importer/app/Repository/ImportJob/ImportJobRepository.php`

## Files to read only (for pattern)
- `data-importer/app/Services/BasisBank/Conversion/RoutineManager.php`
- `data-importer/app/Services/BasisBank/Conversion/Routine/TransactionProcessor.php`

## Execution directive (context hygiene)
- Do not deep-scan or reopen additional files beyond this plan and the listed files.
- Execute this part strictly using this plan context to avoid context overflow.

## Objective
- Integrate interactive BasisBank auth contract into parsing/validation/submission pipeline with controlled legacy fallback.

## Exact implementation scope
- Update collector validation to enforce interactive auth state for new jobs.
- Update import-job parse path to load BasisBank auth session context.
- Update submission job to operate from new BasisBank auth context.
- Keep temporary legacy token/consent fallback for existing persisted jobs only.

## Verification target
- New BasisBank imports require interactive auth context.
- Existing token-based jobs remain non-breaking during migration window.

## Progress
- [ ] Integrate BasisBank interactive auth into validation/parse/submission with backward-safe fallback. Status: implemented, awaiting user confirmation.
  - Files touched: `data-importer/app/Services/BasisBank/Validation/NewJobDataCollector.php`, `data-importer/app/Repository/ImportJob/ImportJobRepository.php`, `data-importer/app/Jobs/ProcessImportSubmissionJob.php`.
  - Non-trivial choice: enforce `BasisBankSessionState::AUTHENTICATED` + artifact for interactive flow, while preserving old `basisbank_api_token` + `basisbank_consent_id` fallback when both values are present and valid.

## Decision Log
- Chose to keep legacy `basisbank_api_token`/`basisbank_consent_id` fields valid for existing persisted jobs and add auth-session fallback only when interactive fields are present/valid.
- Added an explicit runtime hydration step in `ProcessImportSubmissionJob` to keep queue workers safe when session context is not available.
