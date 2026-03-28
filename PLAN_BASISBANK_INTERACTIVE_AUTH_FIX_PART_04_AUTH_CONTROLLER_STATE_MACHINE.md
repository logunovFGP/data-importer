# BasisBank plan_part_04: Auth Controller State Machine

Date: 2026-02-16
Owner: Codex
Status: planning
Depends on: PART 01, PART 03

## Files to edit (minimal)
- `data-importer/app/Http/Controllers/Import/AuthenticateController.php`
- `data-importer/resources/views/v2/import/002-authenticate/index.blade.php`
- `data-importer/routes/web.php` (only if explicit BasisBank step routes are required)

## Files to read only (for pattern)
- `data-importer/app/Http/Controllers/Import/AuthenticateController.php`

## Execution directive (context hygiene)
- Do not deep-scan or reopen additional files beyond this plan and the listed files.
- Execute this part strictly using this plan context to avoid context overflow.

## Decision Log
- Chose a BasisBank-only branch in `AuthenticateController` instead of introducing new controller actions, to keep route contracts unchanged and reuse existing `/authenticate-flow/{flow}` flow.
- Chose form-state persistence in the same controller path using `SecretManager` so OTP/trust steps survive refreshes and user round-trips without requiring new persistent entities.
- Added a step-aware form field visibility rule so BasisBank login/password and OTP/trust-device steps are mutually isolated, reducing chance of submitting an invalid transition payload.

## Objective
- Add deterministic multi-step controller flow for BasisBank authentication.

## Exact implementation scope
- Extend BasisBank branch in `postIndex`:
  - step 1: submit login/password
  - step 2: if OTP required, render OTP state
  - step 3: submit OTP/trusted-device confirmation
  - step 4: transition to authenticated flow
- Keep existing generic auth behavior unchanged for other providers.
- Preserve existing route contracts unless provider-specific endpoints are strictly necessary.

## Verification target
- BasisBank auth can complete in multiple UI submits without manual session hacking.
- Other providers still use existing generic auth submit path.

## Progress
- [ ] Implement BasisBank multi-step auth state machine in controller + auth view. Status: implemented, awaiting user confirmation.
  - Files touched: `app/Http/Controllers/Import/AuthenticateController.php`, `resources/views/v2/import/002-authenticate/index.blade.php`.
- CHO #3: Trust-device is now a separate post-auth step. Initial login/OTP no longer auto-enters trust-device challenge; user is explicitly offered "Enable trusted device" or "Skip and continue" after authentication.
