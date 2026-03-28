# BasisBank plan_part_01: Contract and UI Inputs

Date: 2026-02-16
Owner: Codex
Status: in_progress
Depends on: none

## Files to edit (minimal)
- `data-importer/resources/lang/en/import.php`
- `data-importer/resources/views/v2/import/002-authenticate/index.blade.php`
- `data-importer/app/Services/BasisBank/Authentication/SecretManager.php`

## Files to read only (for pattern)
- `data-importer/app/Http/Controllers/Import/AuthenticateController.php`
- `data-importer/app/Services/BasisBank/AuthenticationValidator.php`

## Execution directive (context hygiene)
- Do not deep-scan or reopen additional files beyond this plan and the listed files.
- Execute this part strictly using this plan context to avoid context overflow.

## Scope and assumptions
- Scope: contract labels and auth form rendering only; no controller routing or job flow rewiring in this part.
- Assumption: keep posted field names backward-compatible (`basisbank_api_token` and `basisbank_consent_id`) until follow-up parts migrate auth-state contract.

## Decision Log
- Kept legacy input names while showing login/password UX labels in this phase to preserve compatibility with current controller/validator behavior.
- Added dedicated BasisBank SecretManager getters/setters with legacy fallback behavior so downstream migration can switch to new names progressively.

## Objective
- Replace BasisBank auth input contract from `api_token/consent_id` to `login/password` with OTP-ready form state.

## Exact implementation scope
- Add BasisBank UI labels/placeholders/help for:
  - `basisbank_login`
  - `basisbank_password`
  - `basisbank_otp_code`
  - `basisbank_request_sms_code`
  - `basisbank_trust_device`
- Remove token/consent wording from BasisBank authentication UX.
- Add OTP-specific render branch in auth form for step-2 challenge input.
- Update `SecretManager` key contract to support login/password and OTP session state keys.

## Verification target
- Opening `authenticate-flow/basisbank` shows login/password first.
- OTP field appears only in OTP-required auth state.

## Progress
- [ ] Implement BasisBank auth form contract migration (`login/password + OTP-ready`) and remove token/consent prompts. Status: implemented, awaiting user confirmation. Touched: `data-importer/resources/lang/en/import.php`, `data-importer/resources/views/v2/import/002-authenticate/index.blade.php`, `data-importer/app/Services/BasisBank/Authentication/SecretManager.php`.
