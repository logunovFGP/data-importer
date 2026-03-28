# TRC20 plan_part_1: Flow registration and schema contract

Date: 2026-02-16
Owner: Codex
Status: in_progress
Depends on: none

## Implementation outcome
- Status: implemented, awaiting user confirmation
- Files edited:
  - `data-importer/config/importer.php`
  - `data-importer/config/trc20.php` (new)
  - `data-importer/.env.example`
  - `data-importer/resources/schemas/v3.json`
- Notes:
  - Added `trc20` provider entry to `config/importer.php` (`title: TRC-20` and non-file-flow compatible flags).
  - Added `config/trc20.php` with env-backed `api_key`, `api_url`, `wallets_endpoint`, `transactions_endpoint`, `request_timeout`, `page_size`, `max_pages`, and `wallets`.
  - Added TRC20 env placeholders in `.env.example` including endpoint/timeout overrides.
  - Added `trc20` to schema flow enum in `v3.json`.

## Files to edit (minimal)
- `data-importer/config/importer.php`
- `data-importer/config/trc20.php` (new)
- `data-importer/.env.example`
- `data-importer/resources/schemas/v3.json`

## Files to read only (for pattern)
- `data-importer/config/basisbank.php`
- `data-importer/config/tbank.php`


## Execution directive (context hygiene)
- Do not deep-scan or reopen additional files beyond this plan and the `Files to read only` list.
- Execute this part strictly using the listed `Files to edit` and this plan context to avoid context overflow.

- Add `trc20` provider block in `config/importer.php` matching token-provider structure.
- Add `config/trc20.php` with API URL, wallet endpoint, transaction endpoint, timeout/pagination defaults, and API key mapping.
- Add env placeholders `TRC20_API_KEY`, `TRC20_WALLETS`, and endpoint overrides if needed.
- Extend `resources/schemas/v3.json` flow enum to include `trc20`.

## Context extracted from scanned files (no additional file scans needed)
- `data-importer/config/importer.php` uses one array of provider definitions keyed by flow names (`basisbank`, `tbank`, ...).
- Existing provider entries are arrays with keys `title`, `enabled`, `conversion_before_mapping`, `explanation`, and `supports_new_accounts`.
- `config/basisbank.php` and `config/tbank.php` are simple return arrays and keep provider URL/keys in the same shape as runtime reads expect.
- `resources/schemas/v3.json` hardcodes flow enum values used by importer validation + API payload checks.
- `.env.example` defines provider credentials in UPPER_CASE with per-provider prefixes.

## Exact implementation sequence
- Edit `data-importer/config/importer.php`:
  - Add `trc20` provider entry with `title: TRC-20`
  - Set `enabled: true`
  - Set `conversion_before_mapping: true`
  - Set `explanation: ''` initially
  - Set `supports_new_accounts: true`
- Create `data-importer/config/trc20.php`:
  - Return array with `api_key`, `api_url`, `wallets_endpoint`, `transactions_endpoint`, `request_timeout`, `page_size`, `max_pages`.
- Update `data-importer/.env.example`:
  - Add `TRC20_API_KEY`
  - Add `TRC20_WALLETS`
  - Add endpoint override variables if they are not derived from defaults.
- Update `data-importer/resources/schemas/v3.json`:
  - Add `"trc20"` to `flow` enum.
- Decision guard:
  - Keep internal flow key `trc20` and user-visible title `TRC-20`.

