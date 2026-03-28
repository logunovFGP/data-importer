# BasisBank plan_part_02: Auth State Persistence Contract

Date: 2026-02-16
Owner: Codex
Status: implemented, awaiting user confirmation
Depends on: PART 01

## Files to edit (minimal)
- `data-importer/app/Services/Shared/Configuration/Configuration.php`
- `data-importer/app/Services/BasisBank/Authentication/SecretManager.php`
- `data-importer/app/Jobs/ProcessImportSubmissionJob.php`

## Files to read only (for pattern)
- `data-importer/app/Http/Controllers/Import/UploadController.php`
- `data-importer/app/Support/Http/Upload/CollectsSettings.php`
- `data-importer/app/Models/ImportJob.php`

## Execution directive (context hygiene)
- Do not deep-scan or reopen additional files beyond this plan and the listed files.
- Execute this part strictly using this plan context to avoid context overflow.

## Scope and assumptions
- Scope: configuration persistence and submit-time context consumption only. Upload route/form wiring is kept in later parts.
- Assumption: interactive auth request details are persisted to job configuration so background execution can proceed after web step completes.

## Decision Log
- Kept existing token/consent field names in compatibility fallback path for legacy jobs, but introduced new login/password/session state fields as primary keys in configuration.
- Added sync-fingerprint inputs for BasisBank using hashes + context flags to reduce leakage and still stabilize state transitions.

## Objective
- Persist BasisBank interactive auth state and session artifact in importer configuration/job context.

## Exact implementation scope
- Add BasisBank auth fields to `Configuration` serialization/deserialization:
  - login
  - password (secure handling path)
  - auth state enum/string
  - OTP/trusted-device flags
  - serialized session/cookie artifact
- Ensure submission pipeline can read new auth context from configuration.
- Keep temporary compatibility read path for legacy token/consent on existing jobs only.

## Verification target
- Saved import configuration contains BasisBank interactive auth context.
- Submission job can consume BasisBank auth context without requiring token/consent for new jobs.

## Progress
- [ ] Implement configuration/job auth-context persistence for BasisBank interactive auth state. Status: implemented, awaiting user confirmation. Touched: `data-importer/app/Services/Shared/Configuration/Configuration.php`, `data-importer/app/Services/BasisBank/Authentication/SecretManager.php`, `data-importer/app/Jobs/ProcessImportSubmissionJob.php`.
