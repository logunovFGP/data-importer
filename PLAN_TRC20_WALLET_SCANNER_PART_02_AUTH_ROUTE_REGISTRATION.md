# TRC20 plan_part_2: Auth route registration

Date: 2026-02-16
Owner: Codex
Status: in_progress
Depends on: PART 1

## Files to edit (minimal)
- `data-importer/app/Http/Controllers/ServiceController.php`
- `data-importer/app/Api/Controllers/ImportFlow/ValidationController.php`
- `data-importer/app/Http/Controllers/Import/AuthenticateController.php`

## Files to read only (for pattern)
- `data-importer/app/Http/Controllers/ServiceController.php` (basisbank and tbank branches)
- `data-importer/app/Api/Controllers/ImportFlow/ValidationController.php` (basisbank and tbank branches)
- `data-importer/app/Http/Controllers/Import/AuthenticateController.php` (basisbank and tbank branches)
- `data-importer/app/Services/BasisBank/AuthenticationValidator.php`
- `data-importer/app/Services/TBank/AuthenticationValidator.php`


## Execution directive (context hygiene)
- Do not deep-scan or reopen additional files beyond this plan and the `Files to read only` list.
- Execute this part strictly using the listed `Files to edit` and this plan context to avoid context overflow.

- Add `trc20` branch in every flow dispatch switch that currently handles `basisbank` and `tbank`.
- Confirm public auth route entry points accept `trc20` flow id and resolve TRC-20 validator classes.
- Keep all default/403 and redirect behavior unchanged for unsupported providers.

## Context extracted from scanned files
- `ServiceController::validateProvider` currently maps flow strings to flow-specific auth validation behavior.
- `ValidationController::validateFlow` already branches by provider and currently matches only `basisbank` and `tbank`.
- `AuthenticateController::getValidator` currently uses the same branching pattern when instantiating `AuthenticationValidatorInterface`.
- Both basisbank and tbank validators are concrete classes that receive request/session-backed settings from the same controller entry points.

## Exact implementation sequence
- In `ServiceController`, add `case 'trc20'` in the flow dispatch and route it to TRC20 auth validation helper(s).
- In `ValidationController`, include `trc20` in provider validation flow guards and error messages.
- In `AuthenticateController`, add `trc20` in the validator selector and return `TRC20` validator instance through existing DI/container pattern.
- Keep flow key as lowercase `trc20` in every branch condition.

## Implementation outcome
- Status: implemented, awaiting user confirmation
- Files edited:
  - `data-importer/app/Http/Controllers/ServiceController.php`
  - `data-importer/app/Api/Controllers/ImportFlow/ValidationController.php`
  - `data-importer/app/Http/Controllers/Import/AuthenticateController.php`
- Notes:
  - Added `trc20` flow dispatch in service-level validation and a `validateTRC20()` helper.
  - Added `trc20` dispatch in API flow validation and a `validateTRC20()` helper.
  - Added `trc20` branch in `AuthenticateController::getValidator()` mapped to `App\Services\TRC20\AuthenticationValidator`.

