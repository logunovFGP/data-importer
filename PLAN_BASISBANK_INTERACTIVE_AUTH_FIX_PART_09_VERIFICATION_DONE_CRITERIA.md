# BasisBank plan_part_09: Verification and Done Criteria

Date: 2026-02-16
Owner: Codex
Status: planning
Depends on: PART 01, PART 02, PART 03, PART 04, PART 05, PART 06, PART 07, PART 08

## Files to edit (minimal)
- `data-importer/PLAN_BASISBANK_INTERACTIVE_AUTH_FIX_PART_09_VERIFICATION_DONE_CRITERIA.md`
- `task_plan.md`
- `findings.md`
- `progress.md`

## Files to read only (for pattern)
- `data-importer/README.md`
- `firefly-iii/DOCKER_LOCAL.md`

## Execution directive (context hygiene)
- Do not deep-scan or reopen additional files beyond this plan and the listed files.
- Execute this part strictly using this plan context to avoid context overflow.

## Objective
- Validate the migrated BasisBank interactive-auth flow end-to-end and document completion evidence.

## Verification checklist
- [ ] Auth page prompts `login/password` as first step for BasisBank.
  - Status: implemented, awaiting user confirmation
  - Evidence source: `data-importer/app/Services/BasisBank/Auth/BasisBankWebAuthClient.php` `sendCredentialLogin()` now drives the login-page-first form flow.
- [ ] OTP challenge appears when bank requires OTP and is accepted on valid code.
  - Status: implemented, awaiting user confirmation
  - Evidence source: `BasisBankWebAuthClient::sendCredentialsWithOtp()` and `BasisBankFormParser::isOtpChallenge()` transitions to `OTP_REQUIRED` with stored OTP validation on submit.
- [ ] Trusted-device branch is handled when challenge is present.
  - Status: implemented, awaiting user confirmation
  - Evidence source: `BasisBankSessionState::TRUST_DEVICE_REQUIRED` handling in `BasisBankWebAuthClient::sendTrustedDeviceConfirmation()`.
- [ ] Post-auth status transitions to authenticated and import flow proceeds.
  - Status: implemented, awaiting user confirmation
  - Evidence source: state machine handling in `sendCredentialLogin()`, `evaluateResult()` plus parser hydration/queue runtime context loading in `ImportJobRepository` and `ProcessImportSubmissionJob`.
- [ ] BasisBank accounts are retrieved from authenticated web session.
  - Status: implemented, awaiting user confirmation
  - Evidence source: `GetAccountsRequest` now branches to `getFromWebSession()` when `sessionArtifact` exists and includes cookie-session parsing fallback.
- [ ] BasisBank transactions are retrieved and converted without token/consent inputs.
  - Status: implemented, awaiting user confirmation
  - Evidence source: `GetTransactionsRequest` web-session path uses `sessionArtifact` and parser updates; `NewJobDataCollector` now accepts interactive context via `BasisBankSessionState::AUTHENTICATED`.
- [ ] Invalid OTP/login errors are actionable (not blank page).
  - Status: implemented, awaiting user confirmation
  - Evidence source: `BasisBankWebAuthClient::evaluateResult()` now sets explicit error messages on OTP/login failures and state transitions.
- [ ] Validation endpoints reflect session-based auth state.
  - Status: implemented, awaiting user confirmation
  - Evidence source: `data-importer/app/Services/BasisBank/AuthenticationValidator.php` now validates authenticated web-session artifact + ping endpoint and maps auth states to `AuthenticationStatus`.

## Completion output
- Record final result in:
  - `task_plan.md`
  - `findings.md`
  - `progress.md`
- Keep each part status as `implemented, awaiting user confirmation` until user confirms.
- This part is now ready for user-driven end-to-end execution in a real BasisBank login session; no automated runtime assertion was executed in this environment.
