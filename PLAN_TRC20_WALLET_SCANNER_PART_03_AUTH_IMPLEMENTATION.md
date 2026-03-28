# TRC20 plan_part_3: TRC20 auth implementation

Date: 2026-02-16
Owner: Codex
Status: in_progress
Depends on: PART 2

## Files to edit (minimal)
- `data-importer/app/Services/TRC20/Authentication/SecretManager.php` (new)
- `data-importer/app/Services/TRC20/AuthenticationValidator.php` (new)

## Files to read only (for pattern)
- `data-importer/app/Services/BasisBank/Authentication/SecretManager.php`
- `data-importer/app/Services/TBank/Authentication/SecretManager.php`
- `data-importer/app/Services/BasisBank/AuthenticationValidator.php`
- `data-importer/app/Services/TBank/AuthenticationValidator.php`
- `data-importer/app/Services/Shared/Authentication/AuthenticationValidatorInterface.php`


## Execution directive (context hygiene)
- Do not deep-scan or reopen additional files beyond this plan and the `Files to read only` list.
- Execute this part strictly using the listed `Files to edit` and this plan context to avoid context overflow.

- Build TRC20 secret manager using existing provider style: session/request/env fallback and explicit API key accessors.
- Build TRC20 auth validator implementing shared interface and using the shared validation error format.
- Enforce minimal required auth input for TRC-20 (`api_key` + at least one wallet definition source).
- Keep failure responses and thrown error types aligned with basisbank and tbank patterns.

## Context extracted from scanned files
- `AuthenticationValidatorInterface` defines the method contract used by `AuthenticateController`.
- BasisBank and TBank secret managers both isolate provider credential lookup from controller-level request handling.
- BasisBank/TBank validators use shared error model and are invoked only after controller route dispatch.
- TRC20 must use the same namespace and constructor shape as existing token providers to keep flow registration changes in part_2 small.

## Exact implementation sequence
- Create namespace/class under `data-importer/app/Services/TRC20/Authentication`.
- Add `SecretManager` with:
  - method for API key retrieval from request payload/session/env fallback,
  - method for wallets input normalization if needed.
- Add `TRC20/AuthenticationValidator` that:
  - implements `AuthenticationValidatorInterface`,
  - validates API key presence,
  - validates wallet input format enough to avoid invalid API requests.
- Ensure both classes are discoverable through imports added in Part 2 branches.

## Implementation outcome
- Status: implemented, awaiting user confirmation
- Files edited:
  - `data-importer/app/Services/TRC20/Authentication/SecretManager.php` (new)
  - `data-importer/app/Services/TRC20/AuthenticationValidator.php` (new)
- Notes:
  - Added `App\\Services\\TRC20\\Authentication\\SecretManager` with session/env/config fallback plus wallet normalization.
  - Added `App\\Services\\TRC20\\AuthenticationValidator` implementing `AuthenticationValidatorInterface`.
  - Validator now requires API key + at least one wallet and validates TRON wallet format.
  - Aligned TRC20 wallet format validation to accept both TRON `41...` hex addresses (including optional `0x` prefix) and Base58 `T...` form, normalized to lowercase before checks.

