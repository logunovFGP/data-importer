# BasisBank plan_part_08: Error Surface and Operator Guidance

Date: 2026-02-16
Owner: Codex
Status: planning
Depends on: PART 04, PART 05

## Files to edit (minimal)
- `data-importer/resources/views/v2/error.blade.php`
- `data-importer/resources/views/v2/import/002-authenticate/index.blade.php`
- `data-importer/resources/lang/en/import.php`

## Files to read only (for pattern)
- `data-importer/app/Http/Controllers/Import/AuthenticateController.php`

## Execution directive (context hygiene)
- Do not deep-scan or reopen additional files beyond this plan and the listed files.
- Execute this part strictly using this plan context to avoid context overflow.

## Objective
- Replace non-actionable BasisBank auth failures with clear operator-facing recovery guidance.

## Exact implementation scope
- Replace placeholder error template with structured error presentation.
- Add BasisBank-specific hints for:
  - invalid login/password
  - invalid/expired OTP
  - trusted-device confirmation required
  - expired session requiring re-authentication
- Ensure auth page surfaces upstream errors without leaking sensitive values.

## Verification target
- Failed BasisBank auth no longer results in blank/empty error page.
- User sees precise recovery instructions for each common failure mode.

## Progress
- [ ] Implement actionable BasisBank auth error rendering and guidance content. Status: implemented, awaiting user confirmation.
  - Files touched: `resources/views/v2/error.blade.php`, `resources/views/v2/import/002-authenticate/index.blade.php`, `resources/lang/en/import.php`

## Decision Log
- Chosen to keep error guidance non-blocking and non-sensitive by using status/message-based hint mapping in the auth form and a shared translated key set instead of exposing backend exception internals.
