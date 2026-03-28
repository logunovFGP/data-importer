# TRC20 plan_part_4: Configuration persistence pipeline

Date: 2026-02-16
Owner: Codex
Status: in_progress
Depends on: PART 3

## Files to edit (minimal)
- `data-importer/app/Services/Shared/Configuration/Configuration.php`
- `data-importer/app/Support/Http/Upload/CollectsSettings.php`
- `data-importer/app/Http/Controllers/Import/UploadController.php`
- `data-importer/app/Http/Request/ConfigurationPostRequest.php`

## Files to read only (for pattern)
- `data-importer/app/Services/Shared/Configuration/Configuration.php` (basisbank/tbank section)
- `data-importer/app/Support/Http/Upload/CollectsSettings.php` (flow settings extraction)
- `data-importer/app/Http/Controllers/Import/UploadController.php` (basisbank/tbank persistence branch)
- `data-importer/app/Http/Request/ConfigurationPostRequest.php` (column option defaults and rules)


## Execution directive (context hygiene)
- Do not deep-scan or reopen additional files beyond this plan and the `Files to read only` list.
- Execute this part strictly using the listed `Files to edit` and this plan context to avoid context overflow.

- Add TRC20 config fields `trc20ApiKey`, `trc20Wallets` (and any normalized derivative used in validation/config) to Configuration model.
- Persist them through all conversion points: `fromArray`, `fromRequest`, `toArray`, and `fromSession` if applicable.
- Add TRC20 settings collector in `CollectsSettings`.
- Add TRC20 save/load branches in `UploadController`.
- Extend request schema defaults/options for TRC20 account mapping and wallet field rendering.

## Context extracted from scanned files
- `Configuration.php` already handles per-provider fields as typed properties and merges payload segments into one domain object.
- `UploadController` currently routes flow settings to provider-specific branches before storing the upload job and configuration payload.
- `CollectsSettings` currently exposes helper methods for basisbank/tbank keys with strict keys from flow name.
- `ConfigurationPostRequest` already has chain-specific defaults and column option controls and should be extended by adding trc20 keys there to avoid ad-hoc request parsing in controllers.

## Exact implementation sequence
- Add new TRC20 properties in `Configuration` with null-safe defaults.
- Extend all parsing and serialization methods in `Configuration` to include TRC20 fields:
  - `fromArray`
  - `fromRequest`
  - `fromSession` if session hydration is used
  - `toArray`
- In `CollectsSettings`, add method(s) to collect `trc20` config keys.
- In `UploadController`, add explicit `trc20` upload branch mirroring basisbank/tbank pattern:
  - instantiate/resolve TRC20 settings
  - persist API key + wallets into job/session/config.
- In `ConfigurationPostRequest`, add:
  - default column list for trc20 accounts
  - rule/validation for `trc20_wallets`
  - mapping defaults needed by account conversion UI.

## Implementation outcome (pending user confirmation)

- Status: implemented, awaiting user confirmation.
- Implemented TRC20 fields in `Configuration` (config model) with end-to-end parse/serialize flow updates:
  - `data-importer/app/Services/Shared/Configuration/Configuration.php`
  - Added properties: `trc20ApiKey`, `trc20Wallets`
  - Updated `fromArray`, `fromRequest`, `toArray`, `updateFromRequest`, and `fromClassicFile` mapping for:
    - `trc20_api_key`
    - `trc20_wallets`
  - Added getters/setters:
    - `getTrc20ApiKey` / `setTrc20ApiKey`
    - `getTrc20Wallets` / `setTrc20Wallets`
  - `fromSession` was not present in current `Configuration`, so no dedicated session hydration branch was needed.
- Added upload prefill support for TRC20:
  - `data-importer/app/Support/Http/Upload/CollectsSettings.php`
  - New helper `getTRC20Settings()`
- Added TRC20 persistence in upload flow:
  - `data-importer/app/Http/Controllers/Import/UploadController.php`
  - Added `TRC20SecretManager` injection import and `index()` settings entry.
  - Added `case 'trc20'` branch to persist `trc20_api_key` and `trc20_wallets` into:
    - import job configuration
    - session via `TRC20\Authentication\SecretManager`.
- Extended request schema for TRC20 form persistence:
  - `data-importer/app/Http/Request/ConfigurationPostRequest.php`
  - Added TRC20 defaults in `getAll()`.
  - Added TRC20 rules in `rules()`.
  - Added `trc20` entry in column options.
  - Added `trc20` to nullable flow account rule set in `getDefaultAccountRule()`.

