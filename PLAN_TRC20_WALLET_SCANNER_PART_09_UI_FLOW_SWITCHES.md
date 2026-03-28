# TRC20 plan_part_9: UI and flow-switch changes

Date: 2026-02-16
Owner: Codex
Status: in_progress
Depends on: PART 4

## Files to edit (minimal)
- `data-importer/resources/views/v2/import/003-upload/index.blade.php`
- `data-importer/resources/views/v2/import/003-upload/partials/trc20.blade.php` (new)
- `data-importer/resources/views/v2/import/004-configure/partials/opening-box.blade.php`
- `data-importer/resources/views/v2/import/004-configure/partials/data-importer-dates.blade.php`
- `data-importer/resources/views/v2/import/004-configure/partials/data-importer-accounts.blade.php`
- `data-importer/resources/lang/en/import.php`

## Files to read only (for pattern)
- `data-importer/resources/views/v2/import/003-upload/partials/basisbank.blade.php`
- `data-importer/resources/views/v2/import/003-upload/partials/tbank.blade.php`
- `data-importer/resources/views/v2/import/004-configure/partials/opening-box.blade.php`
- `data-importer/resources/views/v2/import/004-configure/partials/data-importer-accounts.blade.php`
- `data-importer/resources/lang/en/import.php` (basisbank/tbank labels)


## Execution directive (context hygiene)
- Do not deep-scan or reopen additional files beyond this plan and the `Files to read only` list.
- Execute this part strictly using the listed `Files to edit` and this plan context to avoid context overflow.

- Add TRC20 upload partial with API key and wallets inputs.
- Add TRC20 branch in upload flow switch to render new partial.
- Add TRC20 field rendering/visibility controls in configure flow partials.
- Add translation strings for TRC-20 labels/help and avoid inheriting unsupported defaults.

## Context extracted from scanned files
- Upload page includes a flow switch and per-flow partial include pattern.
- `basisbank` and `tbank` partials expose auth credentials plus date/account style controls.
- Configure wizard pages include flow-aware partials for date ranges and account selection.
- `en/import.php` carries provider-specific labels used by UI and validation messages.

## Exact implementation sequence
- Add `003-upload/partials/trc20.blade.php` with:
  - TRC20 API key input
  - optional wallet list input controls matching existing wallet-list UX.
- Edit `003-upload/index.blade.php` flow switch to include `trc20`.
- Edit `opening-box.blade.php` and `data-importer-dates.blade.php` for TRC20-supported date selectors only (if any).
- Edit `data-importer-accounts.blade.php` to map TRC20 account list/selection.
- Add `import.php` keys:
  - `trc20`, `trc20_api_key`, `trc20_wallets`, and help/error copy.

## Progress
- [ ] UI flow-switch and TRC20 upload partial implemented and wired (`data-importer/resources/views/v2/import/003-upload/index.blade.php`, `data-importer/resources/views/v2/import/003-upload/partials/trc20.blade.php`). Status: implemented, awaiting user confirmation.
- [ ] Configure-step text and date controls updated for TRC20 context/incremental-sync behavior (`data-importer/resources/views/v2/import/004-configure/partials/opening-box.blade.php`, `data-importer/resources/views/v2/import/004-configure/partials/data-importer-dates.blade.php`). Status: implemented, awaiting user confirmation.
- [ ] Account mapping UI adapted for TRC20 wallets (`data-importer/resources/views/v2/import/004-configure/partials/data-importer-accounts.blade.php`). Status: implemented, awaiting user confirmation.
- [ ] TRC20 UI translation strings added for upload fields (`data-importer/resources/lang/en/import.php`). Status: implemented, awaiting user confirmation.

