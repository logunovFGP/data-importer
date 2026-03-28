# PLAN: BasisBank and TBank Provider Integration

Date: 2026-02-12
Status: in_progress

## Scope
- Add first-class `basisbank` and `tbank` import providers to Firefly III Data Importer.
- Add UI token input so token can be changed from importer UI (without editing env files).
- Implement provider authentication/validation, account discovery, and transaction download.
- Keep conversion/mapping/submission behavior aligned with existing third-party providers.

## Constraints
- Reuse existing importer patterns (`lunchflow` / `simplefin`) to minimize regressions.
- Keep all secrets handled via session/configuration fallback paths.
- No git rollback/revert/commit operations.

## Historical plan review
- Checked for existing `PLAN*.md` and `plans/archive/` in `data-importer`: none found.

## Decision Log
- Use token-based API integrations for both providers (instead of full browser automation/OAuth popup flows) because this matches the requested “UI input for token change” and fits importer architecture with lowest implementation risk.
- Implement provider-specific services, but reuse established conversion pipeline structure to avoid custom one-off controller logic.
- For BasisBank, support `Consent-ID` as optional companion input because the source integration metadata indicates PSD2 access typically needs both bearer token and consent context.

## Tasks
- [ ] 1. Register providers and configuration
  - Add provider entries in `config/importer.php`.
  - Add `config/basisbank.php` and `config/tbank.php`.
  - Status: implemented, awaiting user confirmation.
  - Touched: `config/importer.php`, `config/basisbank.php`, `config/tbank.php`, `.env.example`.

- [ ] 2. Add UI/auth wiring for token inputs
  - Add authentication validators + secret managers for BasisBank and TBank.
  - Wire validation endpoints/controllers to new providers.
  - Add translation labels/help texts for auth forms.
  - Add upload-step token input partials for provider token override.
  - Status: implemented, awaiting user confirmation.
  - Touched: `app/Services/BasisBank/AuthenticationValidator.php`, `app/Services/BasisBank/Authentication/SecretManager.php`, `app/Services/TBank/AuthenticationValidator.php`, `app/Services/TBank/Authentication/SecretManager.php`, `app/Http/Controllers/ServiceController.php`, `app/Api/Controllers/ImportFlow/ValidationController.php`, `app/Http/Controllers/Import/AuthenticateController.php`, `resources/lang/en/import.php`, `resources/views/v2/import/003-upload/index.blade.php`, `resources/views/v2/import/003-upload/partials/basisbank.blade.php`, `resources/views/v2/import/003-upload/partials/tbank.blade.php`.

- [ ] 3. Persist provider settings in import configuration
  - Extend configuration model serialization/deserialization for new provider secrets/fields.
  - Wire upload controller + settings collector to pass user-entered values into configuration.
  - Status: implemented, awaiting user confirmation.
  - Touched: `app/Services/Shared/Configuration/Configuration.php`, `app/Support/Http/Upload/CollectsSettings.php`, `app/Http/Controllers/Import/UploadController.php`.

- [ ] 4. Implement BasisBank data flow
  - Add request/response/model/collector/conversion classes for accounts and transactions.
  - Support token auth + optional consent header.
  - Integrate into repository parse flow, conversion factory, configuration account merge, and mapping flow.
  - Status: implemented, awaiting user confirmation.
  - Touched: `app/Services/BasisBank/Request/GetAccountsRequest.php`, `app/Services/BasisBank/Request/GetTransactionsRequest.php`, `app/Services/BasisBank/Validation/NewJobDataCollector.php`, `app/Services/BasisBank/Conversion/Routine/TransactionProcessor.php`, `app/Services/BasisBank/Conversion/RoutineManager.php`, `app/Repository/ImportJob/ImportJobRepository.php`, `app/Services/Shared/Conversion/ConversionRoutineFactory.php`, `app/Http/Controllers/Import/ConfigurationController.php`, `app/Http/Controllers/Import/MapController.php`, `app/Support/Internal/CollectsAccounts.php`, `resources/views/v2/import/004-configure/partials/opening-box.blade.php`, `resources/views/v2/import/004-configure/partials/data-importer-dates.blade.php`.
  - Decision note: BasisBank uses consent-based header enrichment in request path where required by provider spec.

- [ ] 5. Implement TBank data flow
  - Add request/response/model/collector/conversion classes for accounts and transactions.
  - Support bearer token auth against TBank open API endpoints.
  - Integrate into repository parse flow, conversion factory, configuration account merge, and mapping flow.
  - Status: implemented, awaiting user confirmation.
  - Touched: `app/Services/TBank/Request/GetAccountsRequest.php`, `app/Services/TBank/Request/GetTransactionsRequest.php`, `app/Services/TBank/Validation/NewJobDataCollector.php`, `app/Services/TBank/Conversion/Routine/TransactionProcessor.php`, `app/Services/TBank/Conversion/RoutineManager.php`, `app/Repository/ImportJob/ImportJobRepository.php`, `app/Services/Shared/Conversion/ConversionRoutineFactory.php`, `app/Http/Controllers/Import/ConfigurationController.php`, `app/Http/Controllers/Import/MapController.php`, `app/Support/Internal/CollectsAccounts.php`, `app/Http/Request/ConfigurationPostRequest.php`.

- [ ] 6. Fix incremental pull baseline resolution when date fields are null
  - Apply incremental start-date fallback only when no explicit `date_not_before` is provided.
  - Keep manual date range selections (`partial` and `range`) unchanged.
  - Status: implemented, awaiting user confirmation.
  - Touched: `app/Services/BasisBank/Conversion/Routine/TransactionProcessor.php`, `app/Services/TBank/Conversion/Routine/TransactionProcessor.php`.
  - Decision Log: use explicit `null === $dateFrom` check because resolved start dates are `?string` in the processor code path; this avoids silently skipping cursor-based pulls.

- [ ] 7. Verification
  - Run syntax/quality verification commands available in repo.
  - Fix issues introduced by this change.
  - Status: implemented, awaiting user confirmation.
  - Verification outcome:
  - 2026-02-13: Local toolchain check passed (`PHP 8.4.16`, `Composer 2.9.5`).
  - 2026-02-13: `php -l` passed for all touched BasisBank/TBank and integration PHP files.
  - 2026-02-13: `docker compose exec importer php artisan --version` returned `Laravel Framework 12.47.0`.
  - 2026-02-13: Provider validation endpoints responded without server errors:
    - `GET http://localhost:9998/validate/basisbank` => `200 {"result":"NODATA"}`
    - `GET http://localhost:9998/validate/tbank` => `200 {"result":"NODATA"}`
    - `GET http://localhost:9998/api/import-flows/validate/basisbank` => `200 {"result":"NODATA"}`
    - `GET http://localhost:9998/api/import-flows/validate/tbank` => `200 {"result":"NODATA"}`
  - 2026-02-13: Built importer V2 assets locally (`npm install`, `npm --workspace resources/js/v2 run build`) to satisfy bind-mounted local runtime requirements.
  - 2026-02-13: Verified import flow UI pages and token inputs:
    - `GET http://localhost:9998/new-import/basisbank` => `200`, `basisbank_api_token` field present.
    - `GET http://localhost:9998/new-import/tbank` => `200`, `tbank_api_token` field present.
    - Playwright browser snapshots confirm both token input forms are mounted.
  - 2026-02-13: Residual gap: end-to-end live API pull from BasisBank/TBank not executed in this environment because no real provider tokens/consent values were supplied for test runs.
