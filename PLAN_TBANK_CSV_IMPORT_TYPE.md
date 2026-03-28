# Plan: TBank CSV Export Import Type

Date: 2026-02-17
Owner: Codex
Status: in_progress

## Scope
- Add a selectable import type named `TBank from CSV export` for `file` uploads.
- Prefill safe CSV defaults for this type to reduce manual setup.
- Keep existing generic `file` import behavior unchanged by default.

## Constraints
- Reuse existing file workflow (`flow = file`) to avoid new provider/auth wiring risk.
- Keep preset data in config, not hardcoded in view logic.
- Preserve manual configuration and uploaded config file behavior.

## Historical Review
- Reviewed module archive locations under `plans/archive/` and module-level archive folders.
- No relevant archived plan files were found in `data-importer` for this exact request.

## Decision Log
- Chosen approach: add an import-type preset inside `file` flow instead of a new top-level provider key.
- Rationale: lowest risk and production-ready with minimal routing/state-machine changes.
- TBank CSV export sample has no dedicated immutable transaction id column; preset uses a composite pseudo identifier from operation date, card mask, amount, and description.

## Execution Steps
- [ ] Add `file` import type preset configuration for `TBank from CSV export`.
  - Status: implemented, awaiting user confirmation
  - Files touched: `config/file.php`
  - Notes: Added `file.import_types.tbank_csv_export` defaults for delimiter/date/roles and composite pseudo identifier settings.
- [ ] Add upload-form selector for file import type and wire request value through controller.
  - Status: implemented, awaiting user confirmation
  - Files touched: `app/Http/Controllers/Import/UploadController.php`, `resources/views/v2/import/003-upload/partials/file.blade.php`
  - Notes: Added selector with `file_import_type` request field; selected value is preserved on validation failure.
- [ ] Apply preset defaults when selected (only for file flow, without overriding uploaded config content).
  - Status: implemented, awaiting user confirmation
  - Files touched: `app/Http/Controllers/Import/UploadController.php`
  - Notes: Added `applyFileImportTypePreset()`; preset applies only for `flow=file`, only for detected CSV files, and is skipped if user supplied a configuration file.
- [ ] Verify with local syntax/build checks and record outcomes in this plan.
  - Status: implemented, awaiting user confirmation
  - Checks run:
    - `php -l config/file.php` (pass)
    - `php -l app/Http/Controllers/Import/UploadController.php` (pass)
    - `npm --workspace resources/js/v2 run build` (pass; existing Sass deprecation warnings from Bootstrap imports)
