# BasisBank plan_part_03: Web Auth Client

Date: 2026-02-16
Owner: Codex
Status: planning
Depends on: PART 02

## Files to edit (minimal)
- `data-importer/app/Services/BasisBank/Auth/BasisBankWebAuthClient.php` (new)
- `data-importer/app/Services/BasisBank/Auth/BasisBankFormParser.php` (new)
- `data-importer/app/Services/BasisBank/Auth/BasisBankSessionState.php` (new)
- `data-importer/app/Services/BasisBank/Auth/BasisBankOtpService.php` (new)

## Files to read only (for pattern)
- `BasisBank API/ZenPlugins/src/plugins/basisbank/fetchApi.ts`
- `BasisBank API/ZenPlugins/src/plugins/basisbank/models.ts`
- `data-importer/app/Services/Shared/Request/*`

## Execution directive (context hygiene)
- Do not deep-scan or reopen additional files beyond this plan and the listed files.
- Execute this part strictly using this plan context to avoid context overflow.

## Objective
- Implement BasisBank web-session auth engine aligned with reference flow (login/password + OTP + trusted-device challenge).

## Exact implementation scope
- Implement login page fetch + hidden form field parsing.
- Implement login form submit with login/password.
- Detect OTP-required response and support OTP submit.
- Detect trusted-device challenge and support confirmation flow.
- Extract/persist web session artifact for downstream account/transaction requests.
- Normalize auth errors into importer-safe exceptions/messages.

## Verification target
- Service returns deterministic auth states:
  - `OTP_REQUIRED`
  - `TRUST_DEVICE_REQUIRED`
  - `AUTHENTICATED`
  - `AUTH_FAILED`

## Progress
- [ ] Implement BasisBank interactive web auth service classes and auth-state outputs. Status: implemented, awaiting user confirmation. Touched files: `app/Services/BasisBank/Auth/BasisBankWebAuthClient.php`, `app/Services/BasisBank/Auth/BasisBankFormParser.php`, `app/Services/BasisBank/Auth/BasisBankSessionState.php`, `app/Services/BasisBank/Auth/BasisBankOtpService.php`.

## Decision Log
- CHO #1: Hardened auth redirect handling in `BasisBankWebAuthClient::evaluateResult` so non-successful redirect or 4xx responses now map to `AUTH_FAILED` instead of defaulting to `AUTHENTICATED`.
- CHO #2: Added same-cycle trusted-device bootstrap in `BasisBankWebAuthClient` so `request_sms_code` triggers SMS immediately when trust-device challenge is detected after login/OTP submit.
