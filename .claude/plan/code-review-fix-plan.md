# Implementation Plan: Code Review Fix Plan -- 88 Bugs Across Data Importer

## Overview

This plan addresses all 88 bugs found in the Firefly III data-importer codebase, organized into 4 sequential severity waves (CRITICAL, HIGH, MEDIUM, LOW). Each wave is broken into parallel agent batches based on file independence, with dependency ordering within batches. Every fix includes a corresponding PHPUnit unit test.

## Current State

Two fix agents are already running:
- **fix-criticals-batch1**: Fixes #1, #3, #5, #6, #7, #8 (IN PROGRESS)
- **fix-criticals-batch2**: Fixes #4, #9, #10, #14 (IN PROGRESS)

Remaining work: 3 CRITICALs (security) + 24 HIGHs + 32 MEDIUMs + 19 LOWs = **78 fixes remaining**.

## File Conflict Map

Multiple bugs touch the same file. These MUST be addressed sequentially within the same agent, or coordinated across agents:

| File | Bug Numbers | Wave |
|------|-------------|------|
| `Nordigen/Model/Transaction.php` | #6, #7, #9 (in-progress), #59, #60 | CRITICAL/MEDIUM |
| `Configuration.php` | #11, #27, #34, #44, #45, #46 | CRITICAL/HIGH/MEDIUM |
| `SyncStateManager.php` | #12, #48, #87 | CRITICAL/MEDIUM/LOW |
| `BasisBank/Request/GetAccountsRequest.php` | #1 (in-progress), #13, #20, #32, #58, #61 | CRITICAL/HIGH/MEDIUM |
| `BasisBank/Request/GetTransactionsRequest.php` | #20, #33, #42, #43, #61 | HIGH/MEDIUM |
| `BasisBank/Auth/BasisBankFormParser.php` | #2, #57/#86, #70 | MEDIUM/LOW |
| `BasisBank/Authentication/SecretManager.php` | #37, #41, #47 | HIGH/MEDIUM |
| `SimpleFINService.php` | #16, #38 | HIGH |
| `TBank/Authentication/SecretManager.php` | #23, #62, #63 | HIGH/MEDIUM |
| `ConversionRoutineFactory.php` | #15 | HIGH |
| `LunchFlow/Request/Request.php` | #17, #18 | HIGH |
| `TRC20/Request/GetTransactionsRequest.php` | #3 (in-progress), #5 (in-progress), #26, #42 | CRITICAL/HIGH/MEDIUM |
| `Camt/AbstractTransaction.php` | #8 (in-progress), #10 (in-progress) | CRITICAL |
| `Camt/Conversion/TransactionMapper.php` | #36, #49, #68 | HIGH/MEDIUM |
| `TRC20/Response/GetTransactionsResponse.php` | #64, #65 | MEDIUM |
| `TRC20/Conversion/Routine/TransactionProcessor.php` | #31, #67 | HIGH/MEDIUM |
| `ImportServiceAccount.php` | #30 | HIGH |
| `SimpleFIN/Conversion/TransactionTransformer.php` | #69 | MEDIUM |
| `TBank/Conversion/Routine/TransactionProcessor.php` | #66 | MEDIUM |

## Test Infrastructure

**Existing test pattern** (from codebase analysis): Tests extend `Tests\TestCase`, use PHPUnit 10.x, and access private methods via `ReflectionMethod`. Tests go in `tests/Unit/Services/{Provider}/` directories.

**PHPUnit config**: `phpunit.xml` at project root, SQLite in-memory DB, array session/cache drivers.

**New test naming convention**: `{ClassName}{BugNumber}Test.php` to keep tests traceable to fixes.

---

## Wave 1: CRITICAL (13 total -- 10 in-progress, 3 remaining)

### Status of In-Progress Fixes

| Agent | Bugs | Status | Files |
|-------|------|--------|-------|
| fix-criticals-batch1 | #1, #3, #5, #6, #7, #8 | IN PROGRESS | BasisBank/GetAccountsRequest, TRC20/GetTransactionsRequest, Nordigen/Transaction, Camt/AbstractTransaction |
| fix-criticals-batch2 | #4, #9, #10, #14 | IN PROGRESS | TBank/GetTransactionsRequest, Nordigen/Transaction, Camt/AbstractTransaction, Sophtron/Request |

**Coordination note**: Batch1 and batch2 both touch `Nordigen/Model/Transaction.php` (#6/#7 in batch1, #9 in batch2). If both agents edit this file concurrently, a merge conflict is possible. The fix-criticals-batch2 agent should wait for batch1 to finish its Transaction.php edits before applying #9, OR apply #9 independently since the null-safe operator change at lines 550/572 does not overlap with the str_contains/preg_replace changes at lines 337-341.

### Remaining CRITICALs -- Agent: fix-criticals-security

All 3 remaining CRITICALs are security issues. They touch different files and can be done by a single agent sequentially.

#### Fix #11: Configuration.toArray() Leaks Credentials in Downloadable JSON
- **File**: `app/Services/Shared/Configuration/Configuration.php:1064-1075`
- **Problem**: `toArray()` serializes `basis_bank_login`, `basis_bank_password`, `basis_bank_session_artifact`, `t_bank_api_token`, `trc20_api_key`, `lunch_flow_api_key`, and `access_token` as plaintext. This array is used to generate a downloadable JSON configuration file.
- **Fix**: Add a `toExportArray()` method that calls `toArray()` then strips sensitive fields (replaces with `'***REDACTED***'`). Update the download controller to use `toExportArray()`. Alternatively, redact in `toArray()` itself if no internal code relies on reading credentials from the array.
- **Risk**: HIGH -- must verify that no internal code path reads credentials from the `toArray()` output. The 3 hydration methods at lines 275, 443, 556 read from input arrays (fromFile, fromArray, fromSessionFile), NOT from `toArray()` output. The `toSessionArray()` method at line 996 calls `toArray()` but sessions already have the credentials via SecretManager. Safe to redact in export path only.
- **Action**:
  1. Create `toExportArray()` that calls `toArray()` then unsets credential keys
  2. Grep for all callers of `toArray()` to find the download path
  3. Update the download controller/route to use `toExportArray()`
  4. Keep `toArray()` intact for internal session/serialization use
- **Test file**: `tests/Unit/Services/Shared/Configuration/ConfigurationCredentialRedactionTest.php`
- **Test strategy**: Create Configuration from array with fake credentials, call `toExportArray()`, assert credential fields are `'***REDACTED***'` or absent, assert non-credential fields are preserved.
- **Dependencies**: None

#### Fix #12: SyncStateManager Writes Unencrypted State Without File Locking
- **File**: `app/Services/Shared/SyncState/SyncStateManager.php:116-147`
- **Problem**: `writeState()` at line 146 uses `file_put_contents()` without `LOCK_EX` flag, risking corruption under concurrent writes. State file contains provider names, account IDs, and context hashes in plaintext -- not passwords, but still identifiable data.
- **Fix**:
  1. Add `LOCK_EX` flag to `file_put_contents()` call at line 146
  2. Encrypt the JSON payload using `Crypt::encryptString()` before writing (matching the pattern from `ProviderSecretStore` at `app/Services/Shared/Secrets/ProviderSecretStore.php:118`)
  3. Update `readState()` to decrypt with `Crypt::decryptString()` before JSON parsing, with graceful fallback for unencrypted legacy files
- **Risk**: MEDIUM -- existing state files are plaintext JSON. Need migration path: if decrypt fails, try reading as raw JSON (legacy fallback), then re-encrypt on next write.
- **Test file**: `tests/Unit/Services/Shared/SyncState/SyncStateManagerSecurityTest.php`
- **Test strategy**: Mock `storage_path()`, write state, read back, verify content is encrypted (not valid JSON when read raw). Test legacy fallback path.
- **Dependencies**: None

#### Fix #13: BasisBank Session Recovery Auto-Triggers SMS OTP Without Consent
- **File**: `app/Services/BasisBank/Request/GetAccountsRequest.php:3087-3137`
- **Problem**: `recoverWebSessionForCardModule()` at line 3111 calls `BasisBankWebAuthClient::start()` which may trigger an SMS OTP send. This happens automatically during session recovery without user consent, consuming rate-limited SMS resources silently.
- **Fix**: Instead of calling `start()`, detect the expired session and return a `SESSION_EXPIRED_NEEDS_REAUTH` status. The UI should then redirect the user to the authentication step where they can explicitly consent to re-authentication.
  1. Replace the `$client->start()` call with an exception that carries a structured status code
  2. Create a new exception type or use the existing `ImporterHttpException` with a specific status code (e.g., 419 for session expired)
  3. The calling code should catch this and redirect to re-authentication flow
- **Risk**: HIGH -- this changes the recovery flow. Must verify that callers of `recoverWebSessionForCardModule()` handle the new exception type. The method is `private` so only called within `GetAccountsRequest.php`.
- **Test file**: `tests/Unit/Services/BasisBank/Request/SessionRecoveryNoAutoSmsTest.php`
- **Test strategy**: Test that when session recovery is triggered, the method throws a specific exception rather than calling `start()`. Use reflection to test the private method, or test the caller behavior.
- **Dependencies**: None, but coordinates with #1 (in-progress) which also touches GetAccountsRequest.php. **Must wait for fix-criticals-batch1 to complete** before editing this file.

---

## Wave 2: HIGH (24 fixes)

### Phase 2A: Independent Quick Fixes (parallelizable)

These fixes touch separate files with no interdependencies. Can be run as 3-4 parallel agents.

#### Agent: fix-high-factory-and-simplefin (Fixes #15, #16, #38)

##### Fix #15: Spectre/EnableBanking Missing from ConversionRoutineFactory
- **File**: `app/Services/Shared/Conversion/ConversionRoutineFactory.php:49-96`
- **Problem**: The factory handles flows `file`, `sophtron`, `nordigen`, `simplefin`, `lunchflow`, `basisbank`, `tbank`, `trc20` but NOT `spectre` or `eb` (Enable Banking), even though both are registered providers in `config/importer.php` and both have `RoutineManager` classes at `app/Services/Spectre/Conversion/RoutineManager.php` and `app/Services/EnableBanking/Conversion/RoutineManager.php`.
- **Fix**: Add two `if` blocks:
  ```php
  if ('spectre' === $flow) {
      return new SpectreRoutineManager($this->importJob);
  }
  if ('eb' === $flow) {
      return new EnableBankingRoutineManager($this->importJob);
  }
  ```
- **Test file**: `tests/Unit/Services/Shared/Conversion/ConversionRoutineFactoryTest.php`
- **Test strategy**: Mock ImportJob to return each flow string, verify correct RoutineManager class is returned. Test that 'spectre' and 'eb' no longer throw.

##### Fix #16: SimpleFIN sprintf('%d', $transactions) Passes Array
- **File**: `app/Services/SimpleFIN/SimpleFINService.php:206`
- **Problem**: Line 206: `sprintf('Found %d transactions.', $transactions)` where `$transactions` is an array. Should be `count($transactions)`.
- **Fix**: Change `$transactions` to `count($transactions)` in the sprintf call.
- **Test file**: `tests/Unit/Services/SimpleFIN/SimpleFINServiceLogTest.php`
- **Test strategy**: This is a logging-only bug. Test that `fetchFreshTransactions()` does not trigger a type error when processing results. Can verify via reflection or by testing the method's return type consistency.

##### Fix #38: SimpleFIN Access Token Logged in Plaintext
- **File**: `app/Services/SimpleFIN/SimpleFINService.php:120`
- **Problem**: Line 120 logs the full access token URL. The access token contains credentials embedded in the URL.
- **Fix**: Parse the URL and log only the host/scheme portion: `parse_url($this->accessToken, PHP_URL_HOST)`.
- **Test file**: Same as #16 test file, add a test case.

#### Agent: fix-high-lunchflow-sophtron (Fixes #17, #18, #19)

##### Fix #17: LunchFlow Undeclared $parameters + Query Params Dropped
- **File**: `app/Services/LunchFlow/Request/Request.php:65`
- **Problem**: `setParameters()` at line 62 assigns to `$this->parameters` but no `$parameters` property is declared on the class. Also, `authenticatedGet()` at line 78 does not append query parameters to the URL.
- **Fix**:
  1. Add `private array $parameters = [];` property declaration
  2. In `authenticatedGet()`, append parameters: `$fullUrl = sprintf('%s/%s?%s', $this->getBase(), $this->getUrl(), http_build_query($this->parameters));` (only when parameters are non-empty)
- **Test file**: `tests/Unit/Services/LunchFlow/Request/RequestParametersTest.php`
- **Test strategy**: Create a concrete subclass of the abstract Request, test that setParameters stores values and that getters work.

##### Fix #18: LunchFlow Dead getDefaultHeaders() from Spectre
- **File**: `app/Services/LunchFlow/Request/Request.php:160-173`
- **Problem**: `getDefaultHeaders()` references `$this->expiresAt`, `$this->getAppId()`, `$this->getSecret()` -- none of which exist on the LunchFlow Request class. This is dead code copy-pasted from Spectre's Request class. The method is `protected` but never called.
- **Fix**: Delete the `getDefaultHeaders()` method entirely.
- **Test file**: Same as #17 test file.
- **Dependencies**: Must do after #17 (same file).

##### Fix #19: Sophtron 5 Methods Throw "Should not be necessary"
- **File**: `app/Services/Sophtron/Request/Request.php` (multiple methods)
- **Problem**: Several methods throw `ImporterHttpException('Should not be necesary')` [sic]. These are inherited abstract methods that this provider doesn't use.
- **Fix**: Remove the dead methods if they're not required by an interface/abstract parent. If required by interface, change to throw `\BadMethodCallException('Not implemented for Sophtron provider')`.
- **Test file**: `tests/Unit/Services/Sophtron/Request/RequestDeadMethodsTest.php`

#### Agent: fix-high-security (Fixes #37)

##### Fix #37: BasisBank Credentials Unencrypted in PHP Session
- **File**: `app/Services/BasisBank/Authentication/SecretManager.php:117-131`
- **Problem**: `saveLogin()` and `savePassword()` store plaintext credentials in the PHP session via `session()->put()`. The `ProviderSecretStore` (at `app/Services/Shared/Secrets/ProviderSecretStore.php`) already encrypts data, but the session values are plaintext.
- **Fix**: Use `Crypt::encryptString()` when saving to session, `Crypt::decryptString()` when reading. Update all getters (`getLogin()`, `getPassword()`) to decrypt. Apply same pattern to `saveOtpCode()`, `saveAuthState()`, `saveSessionArtifact()`.
- **Risk**: MEDIUM -- changing session storage format means existing sessions become unreadable. Add try/catch on decrypt to handle legacy plaintext values gracefully.
- **Test file**: `tests/Unit/Services/BasisBank/Authentication/SecretManagerEncryptionTest.php`
- **Test strategy**: Mock session, save credentials, verify stored values are not plaintext. Verify getters return original plaintext after decryption.
- **Dependencies**: None, but coordinates with MEDIUM bugs #41, #47 which also touch SecretManager files. Fix #37 first.

### Phase 2B: Duplication Extraction (must be sequential within each provider)

These are the large extraction refactors. They create shared utilities that subsequent fixes depend on. Each extraction agent works on one provider domain.

#### Agent: fix-high-basisbank-extract (Fixes #20, #32, #33)

**IMPORTANT**: Must wait for fix-criticals-batch1 (#1) to complete, as it modifies `GetAccountsRequest.php`.

##### Fix #20: Extract BasisBankWebSessionTrait (15+ duplicated methods)
- **Files**:
  - Source: `app/Services/BasisBank/Request/GetAccountsRequest.php` (3374 lines) and `app/Services/BasisBank/Request/GetTransactionsRequest.php` (2158 lines)
  - Target: New file `app/Services/BasisBank/Request/BasisBankWebSessionTrait.php`
- **Problem**: 15+ methods are duplicated between the two request files: session cookie management, CardModule AJAX calls, ASP.NET form handling, statement extraction, etc.
- **Fix**:
  1. Identify all methods that appear in both files with identical or near-identical implementations
  2. Extract them into `BasisBankWebSessionTrait`
  3. Both request classes `use BasisBankWebSessionTrait`
  4. Verify no method signature differences
- **Risk**: HIGH -- these are 3374 and 2158 line files. Extraction must be methodical. Each extracted method must be tested.
- **Test file**: `tests/Unit/Services/BasisBank/Request/BasisBankWebSessionTraitTest.php`
- **Test strategy**: Create a concrete test class that uses the trait, test each extracted method in isolation.
- **Dependencies**: None (but enables #32, #33)

##### Fix #32: Reduce GetAccountsRequest.php from 3374 lines
- **File**: `app/Services/BasisBank/Request/GetAccountsRequest.php`
- **Problem**: File is 3374 lines, far exceeding the 800-line maximum.
- **Fix**: After #20 extracts shared methods to trait, the file should shrink significantly. Further decompose by extracting:
  - PSD2 API methods to `BasisBankPsd2Client.php`
  - Statement extraction methods to `StatementExtractor.php`
  - HTML parsing helpers already belong in `BasisBankFormParser`
- **Dependencies**: Requires #20 first

##### Fix #33: Reduce GetTransactionsRequest.php from 2158 lines
- **File**: `app/Services/BasisBank/Request/GetTransactionsRequest.php`
- **Problem**: File is 2158 lines.
- **Fix**: After #20 extracts shared methods, further decompose remaining provider-specific logic.
- **Dependencies**: Requires #20 first

#### Agent: fix-high-shared-extract (Fixes #21, #27, #28, #29, #30, #31, #34)

##### Fix #21: Extract selectDominantCurrency to CurrencyCode
- **Files**:
  - Duplicated in: `BasisBank/Request/GetTransactionsRequest.php`, `TBank/Request/GetTransactionsRequest.php`, `TRC20/Request/GetTransactionsRequest.php`
  - Target: `app/Services/Shared/Support/CurrencyCode.php` (already exists)
- **Problem**: `selectDominantCurrency()` is copied across 3 providers.
- **Fix**: Add `public static function selectDominant(array $currencies): string` to `CurrencyCode`. Replace all 3 copies with `CurrencyCode::selectDominant()`.
- **Test file**: `tests/Unit/Services/Shared/Support/CurrencyCodeTest.php`
- **Dependencies**: Must wait for batch1 (#3/#5 touch TRC20/GetTransactionsRequest) and batch2 (#4 touches TBank/GetTransactionsRequest) to complete.

##### Fix #27: Extract hydrateProviderCredentials() in Configuration
- **File**: `app/Services/Shared/Configuration/Configuration.php:275,443,556`
- **Problem**: The credential hydration block (BasisBank login/password/authState/sessionArtifact/requestSmsCode/trustDevice, TBank token, TRC20 apiKey/wallets, LunchFlow apiKey) is copy-pasted in 3 methods: `fromFile()`, `fromArray()`, `fromSessionFile()`.
- **Fix**: Extract a private method `hydrateProviderCredentials(self $object, array $data): void` and call it from all 3 sites.
- **Test file**: `tests/Unit/Services/Shared/Configuration/ConfigurationHydrationTest.php`
- **Test strategy**: Create configuration from array with provider credentials, verify all credential getters return expected values.
- **Dependencies**: Must do before #34 (file size reduction). Coordinates with #11 (same file).

##### Fix #28: Extract StatusTrackerTrait from ConversionStatus/SubmissionStatus
- **Files**:
  - Source: `app/Services/Shared/Conversion/ConversionStatus.php` and `app/Services/Shared/Import/Status/SubmissionStatus.php`
  - Target: New file `app/Services/Shared/Status/StatusTrackerTrait.php`
- **Problem**: `addError()`, `addWarning()`, `addMessage()`, `addActivity()`, `addBoardEntry()`, `updateBoardEntryStatus()` are duplicated with identical implementations in both classes.
- **Fix**: Extract these 6 methods and their backing arrays (`$errors`, `$warnings`, `$messages`, `$activityLog`, `$transactionBoard`, `$transactionBoardTotal`, `$transactionBoardHidden`) into a shared trait.
- **Test file**: `tests/Unit/Services/Shared/Status/StatusTrackerTraitTest.php`

##### Fix #29: AccountMapper.normalizeCurrencyCode() Skips Validation
- **File**: `app/Services/Shared/Conversion/AccountMapper.php:447`
- **Problem**: `normalizeCurrencyCode()` just does `strtoupper(trim())` -- it does not handle numeric ISO codes or validate length, unlike `CurrencyCode::normalizeOrEmpty()` which does both.
- **Fix**: Delete `normalizeCurrencyCode()` and replace calls with `CurrencyCode::normalizeOrEmpty()`. Two call sites: line 69 and line 407.
- **Test file**: `tests/Unit/Services/Shared/Conversion/AccountMapperCurrencyTest.php`
- **Dependencies**: None (CurrencyCode already exists)

##### Fix #30: Extract sanitizeIban() Helper from ImportServiceAccount
- **File**: `app/Services/Shared/Model/ImportServiceAccount.php`
- **Problem**: The pattern `if ('' !== $iban && false === IbanConverter::isValidIban($iban)) { $iban = ''; }` is repeated 7 times.
- **Fix**: Extract to `private static function sanitizeIban(string $iban): string` that returns empty string for invalid IBANs. Replace all 7 call sites.
- **Test file**: `tests/Unit/Services/Shared/Model/ImportServiceAccountIbanTest.php`

##### Fix #31: Consolidate normalizeServiceAccount() Divergence
- **Files**: `app/Services/TRC20/Conversion/Routine/TransactionProcessor.php` and `app/Support/Internal/CollectsAccounts.php`
- **Problem**: Two implementations of `normalizeServiceAccount()` with diverged logic.
- **Fix**: Identify the canonical version, move to a shared trait or utility, replace both call sites.
- **Test file**: `tests/Unit/Services/Shared/Conversion/NormalizeServiceAccountTest.php`

##### Fix #34: Reduce Configuration.php from 1409 Lines
- **File**: `app/Services/Shared/Configuration/Configuration.php`
- **Fix**: After #27 extracts `hydrateProviderCredentials()`, further reduce by:
  1. Extracting date range logic (lines 1091-1174) to `DateRangeResolver.php`
  2. Extracting `calcDateNotBefore()` (lines 1156-1174) to same
  3. Moving getter/setter pairs for rarely-used properties to sub-config objects
- **Dependencies**: Requires #27 first. Coordinates with #11, #44, #45, #46.

#### Agent: fix-high-trc20-tbank-extract (Fixes #22, #23, #24, #25, #26)

**Must wait for batch1 (#3, #5) and batch2 (#4) to complete**.

##### Fix #22: Extract requestHeaders to TRC20BaseRequest
- **Files**:
  - Duplicated in: `TRC20/Request/GetTransactionsRequest.php`, `TRC20/Request/GetTrxTransactionsRequest.php`, `TRC20/Request/GetWalletRequest.php`
  - Target: New file `app/Services/TRC20/Request/TRC20BaseRequest.php` or add to existing parent
- **Problem**: `requestHeaders()` method is copied across 3 TRC20 request files.
- **Fix**: Extract to a base class or trait. All 3 classes extend `BearerJsonRequest` -- add `requestHeaders()` there or create `TRC20RequestTrait`.
- **Test file**: `tests/Unit/Services/TRC20/Request/TRC20BaseRequestTest.php`

##### Fix #23: Extract Cookie Parser from TBank
- **Files**:
  - Duplicated in: `TBank/OAuthClient.php` (lines 309+) and `TBank/Authentication/SecretManager.php` (lines 246+, 270+)
  - Target: New file `app/Services/TBank/Support/CookieParser.php`
- **Problem**: `extractSessionIdFromCookieHeader()` and `normalizeCookieHeader()`/`mergeCookieHeaders()` are duplicated.
- **Fix**: Create `TBank\Support\CookieParser` with static methods, replace both usages.
- **Test file**: `tests/Unit/Services/TBank/Support/CookieParserTest.php`

##### Fix #24: Extract firstNonEmpty() to SessionJsonRequest
- **Files**: Duplicated in `TBank/Request/GetTransactionsRequest.php` and `TBank/Request/GetAccountsRequest.php`
- **Target**: Move to parent class `app/Services/TBank/Request/SessionJsonRequest.php`
- **Fix**: Add `protected function firstNonEmpty(array $keys, array $data): string` to `SessionJsonRequest`.
- **Test file**: `tests/Unit/Services/TBank/Request/SessionJsonRequestTest.php`

##### Fix #25: GetWalletRequest Delegates to TRC20TokenFilter
- **File**: `app/Services/TRC20/Request/GetWalletRequest.php:186-206`
- **Problem**: `getSupportedTokens()` and `isTokenSupported()` are exact duplicates of `TRC20TokenFilter::getSupportedTokens()` and `TRC20TokenFilter::isTokenInSupportedList()`.
- **Fix**: Delete both private methods, replace calls with `TRC20TokenFilter::getSupportedTokens()` and `TRC20TokenFilter::isTokenInSupportedList()`.
- **Test file**: `tests/Unit/Services/TRC20/Request/GetWalletRequestTokenFilterTest.php`

##### Fix #26: Extract buildQuery/extractFingerprint from TRC20
- **Files**: Duplicated in `TRC20/Request/GetTransactionsRequest.php` and `TRC20/Request/GetTrxTransactionsRequest.php`
- **Problem**: Both files have `buildQuery()` and `extractFingerprint()` with inconsistent `dateTo` handling.
- **Fix**: Extract to the TRC20 base class created in #22. Unify the `dateTo` logic.
- **Test file**: `tests/Unit/Services/TRC20/Request/TRC20QueryBuildTest.php`
- **Dependencies**: Requires #22 first (same base class)

### Phase 2C: File Size Reduction (depends on Phase 2B extractions)

#### Fix #35: Reduce BasisBankWebAuthClient.php from 909 lines
- **File**: `app/Services/BasisBank/Auth/BasisBankWebAuthClient.php`
- **Dependencies**: Can proceed independently of Phase 2B since it's a different file than the request classes.
- **Fix**: Extract OTP handling to `BasisBankOtpService` (already exists at `app/Services/BasisBank/Auth/BasisBankOtpService.php` -- verify and extend). Extract device fingerprint generation to `BasisBankFormParser` (already has some of this).

#### Fix #36: Reduce Camt TransactionMapper.php from 821 lines
- **File**: `app/Services/Camt/Conversion/TransactionMapper.php`
- **Fix**: Extract amount processing methods (#49, #68 fixes overlap here). Extract address generation methods. Extract account matching logic.
- **Dependencies**: Can proceed independently.

---

## Wave 3: MEDIUM (32 fixes)

### Phase 3A: Independent Quick Fixes (parallelizable, 4 agents)

#### Agent: fix-medium-basisbank (Fixes #2, #42, #43, #55, #57, #58, #61)

**Must wait for Wave 2 BasisBank extraction (#20) to complete.**

##### Fix #2: BasisBankFormParser Checkbox Detection Bug
- **File**: `app/Services/BasisBank/Auth/BasisBankFormParser.php:317,358`
- **Problem**: `null === $node->getAttribute('checked')` -- DOMElement::getAttribute() returns empty string `''` when attribute is absent, never `null`. This means checked and unchecked checkboxes are treated identically (both skip the element).
- **Fix**: Change to `!$node->hasAttribute('checked')` at both line 317 and line 358.
- **Test file**: `tests/Unit/Services/BasisBank/Auth/BasisBankFormParserCheckboxTest.php`
- **Test strategy**: Parse HTML with checked and unchecked checkboxes, verify only checked ones appear in the result.

##### Fix #42: Static $webSessionRowsCache Never Cleared
- **File**: `app/Services/BasisBank/Request/GetTransactionsRequest.php:38`
- **Problem**: Static cache persists across requests in long-running processes.
- **Fix**: Convert to instance property, or add `public static function clearCache(): void`.
- **Test file**: `tests/Unit/Services/BasisBank/Request/WebSessionRowsCacheTest.php`

##### Fix #43: normalizeDate() Defaults to Today on Failure
- **File**: `app/Services/BasisBank/Request/GetTransactionsRequest.php:2107`
- **Problem**: Unparseable date silently defaults to today, masking data issues.
- **Fix**: Throw `ImporterErrorException` on unparseable date instead of silent fallback.
- **Test file**: `tests/Unit/Services/BasisBank/Request/NormalizeDateTest.php`

##### Fix #55: BasisBankSessionState Mutation-at-a-Distance
- **File**: `app/Services/BasisBank/Auth/BasisBankWebAuthClient.php`
- **Fix**: Document mutation points or make `BasisBankSessionState` immutable (return new instances from setters).
- **Risk**: LOW -- documentation change primarily.

##### Fix #57: Duplicate Method Alias in BasisBankFormParser
- **File**: `app/Services/BasisBank/Auth/BasisBankFormParser.php:40-53`
- **Problem**: `extractFormFieldsFromLoginPage()` at line 50 is just an alias for `getFormFieldsFromLoginPage()` at line 40.
- **Fix**: Find all callers of `extractFormFieldsFromLoginPage()`, replace with `getFormFieldsFromLoginPage()`, delete the alias.
- **Test file**: Part of #2 test file.

##### Fix #58: extractStatementIdsFromHtml Called Twice
- **File**: `app/Services/BasisBank/Request/GetAccountsRequest.php:291,331`
- **Problem**: Same HTML parsed twice for statement IDs.
- **Fix**: Cache the result of the first call in a local variable, reuse for the second.
- **Test file**: `tests/Unit/Services/BasisBank/Request/StatementIdCacheTest.php`

##### Fix #61: shouldUseStatementFallback() Divergence
- **Files**: `GetAccountsRequest.php` vs `GetTransactionsRequest.php`
- **Fix**: Unify in `BasisBankWebSessionTrait` (created in #20).
- **Dependencies**: Requires #20

#### Agent: fix-medium-config (Fixes #44, #45, #46, #48, #50)

##### Fix #44: Carbon::createFromFormat Not Checked for false
- **File**: `app/Services/Shared/Configuration/Configuration.php:1132`
- **Problem**: `Carbon::createFromFormat('Y-m-d', $before)` can return `false` on invalid input. Line 1138 then compares `$before > $after` which fails if either is `false`. Line 1142 calls `$before->format()` on `false`.
- **Fix**: Add validation after each `createFromFormat()` call. If `false`, throw `ImporterErrorException` with a descriptive message.
- **Test file**: `tests/Unit/Services/Shared/Configuration/ConfigurationDateRangeTest.php`

##### Fix #45: pendingTransactions Assigned Twice
- **File**: `app/Services/Shared/Configuration/Configuration.php:460,497`
- **Problem**: In one of the `from*()` methods, `$object->pendingTransactions` is set at line 460, then overwritten at line 497 (in the `fromSessionFile` path).
- **Fix**: Remove the first assignment at line 460 (the second one at 497 is the correct one as it comes after the block boundary).
- **Test file**: Part of #44 test file.

##### Fix #46: calcDateNotBefore Returns null, Callers Expect String
- **File**: `app/Services/Shared/Configuration/Configuration.php:1156`
- **Problem**: `calcDateNotBefore()` returns `?string` but callers at lines 1113, 1121 assign the result to string properties without null checking.
- **Fix**: Change return type to `string`, return `''` instead of `null` when unit is unrecognized. Also fix the mutation bug: `$today->{$function}($number)` mutates but result is correct since it's returned.
- **Test file**: Part of #44 test file.

##### Fix #48: SyncStateManager Mutates Passed Carbon
- **File**: `app/Services/Shared/SyncState/SyncStateManager.php:99`
- **Problem**: `getIncrementalDateFromCursor()` calls `$cursor->startOfDay()` and `$cursor->subDays()` which mutate the passed Carbon instance.
- **Fix**: Use `$cursor->copy()->startOfDay()` to avoid mutating the caller's Carbon.
- **Test file**: `tests/Unit/Services/Shared/SyncState/SyncStateManagerMutationTest.php`
- **Test strategy**: Pass a Carbon instance, call `getIncrementalDateFromCursor()`, verify the original Carbon is unchanged.
- **Dependencies**: Coordinates with #12 (same file, but different method).

##### Fix #50: CSV Date Converter Mutates $this->dateFormat
- **File**: `app/Services/CSV/Converter/Date.php:60`
- **Problem**: Line 60-61: `$this->dateFormat = sprintf('!%s', $this->dateFormat)` -- prepends `!` on every call, so repeated calls produce `!!format`.
- **Fix**: Use a local variable: `$format = $this->dateFormat; if ('!' !== $format[0]) { $format = '!'.$format; }`
- **Test file**: `tests/Unit/Services/CSV/Converter/DateMutationTest.php`

#### Agent: fix-medium-nordigen-eb (Fixes #39, #51, #52, #53, #54, #59, #60)

##### Fix #39: TBank OAuth State Bypass
- **File**: `app/Services/TBank/OAuthClient.php:75-80`
- **Problem**: When `$expectedClientState` is set but `$clientState` is empty, the check passes (line 77: `'' !== trim($clientState)` is false, so the hash_equals is skipped). An attacker could bypass state validation by omitting the state parameter.
- **Fix**: If `$expectedClientState` is non-empty, both `$clientState` must also be non-empty AND match. Change to: `if ('' !== $expectedClientState && '' === trim($clientState)) { throw ...; }`
- **Test file**: `tests/Unit/Services/TBank/OAuthClientStateTest.php`

##### Fix #51: EnableBanking json_encode Not Checked for false
- **File**: `app/Services/EnableBanking/Model/Transaction.php:112`
- **Problem**: `$encoded = json_encode($array)` can return `false`. Line 113 passes `false` to `json_validate()`.
- **Fix**: Check `false !== $encoded` before calling `json_validate()`.
- **Test file**: `tests/Unit/Services/EnableBanking/Model/TransactionJsonTest.php`

##### Fix #52: 5 Providers Duplicate Request Base Class
- **Files**: `Nordigen/Request/Request.php`, `Spectre/Request/Request.php`, `Sophtron/Request/Request.php`, `LunchFlow/Request/Request.php`, `EnableBanking/Request/Request.php`
- **Target**: `app/Services/Shared/Request/AbstractApiRequest.php`
- **Problem**: Each provider has its own abstract `Request` base class with duplicated `setTimeOut()`, `getBase()`, `setBase()`, `getUrl()`, `setUrl()`, `getClient()`.
- **Fix**: Create `AbstractApiRequest` with the shared boilerplate. Each provider's Request extends it.
- **Risk**: MEDIUM -- must verify each provider's Request has compatible method signatures.
- **Test file**: `tests/Unit/Services/Shared/Request/AbstractApiRequestTest.php`

##### Fix #53: Duplicated filterTransactions()
- **Files**: `EnableBanking/Conversion/Routine/TransactionProcessor.php` and `LunchFlow/Conversion/Routine/TransactionProcessor.php`
- **Target**: `app/Services/Shared/Conversion/TransactionFilterTrait.php`
- **Fix**: Extract common filter logic to trait.
- **Test file**: `tests/Unit/Services/Shared/Conversion/TransactionFilterTraitTest.php`

##### Fix #54: LunchFlow filterTransactions() Never Filters Pending
- **File**: `app/Services/LunchFlow/Conversion/Routine/TransactionProcessor.php:140`
- **Problem**: The filter function exists but does not check `$transaction->hold` or pending status.
- **Fix**: Add pending check: `if ($transaction->hold && !$this->includePending) { continue; }`.
- **Dependencies**: Do after #53 (same code being extracted).

##### Fix #59/#60: Duplicated Transaction ID Generation
- **Files**: `Nordigen/Model/Transaction.php:347-356` and `EnableBanking/Model/Transaction.php:160-166`
- **Target**: `app/Services/Shared/Support/TransactionIdGenerator.php`
- **Problem**: Nearly identical `getTransactionId()` -- both trim whitespace, truncate to 125 chars, and join with `-`.
- **Fix**: Create shared static utility `TransactionIdGenerator::generate(string $accountId, string $transactionId): string`.
- **Test file**: `tests/Unit/Services/Shared/Support/TransactionIdGeneratorTest.php`

#### Agent: fix-medium-misc (Fixes #40, #41, #47, #49, #56, #62, #63, #64, #65, #66, #67, #68, #69)

##### Fix #40: TRC20 SecretManager Logs Partial API Key
- **File**: `app/Services/TRC20/Authentication/SecretManager.php` (or wherever TRC20 SecretManager logs)
- **Fix**: Log only key length, not the key itself. Change to `sprintf('API key configured (%d chars)', strlen($apiKey))`.
- **Test file**: `tests/Unit/Services/TRC20/Authentication/SecretManagerLogTest.php`

##### Fix #41: asBool() Inconsistency
- **Files**: `BasisBank/Authentication/SecretManager.php` and `BasisBank/AuthenticationValidator.php`
- **Problem**: Both have `asBool()` with potentially different implementations.
- **Fix**: Verify both implementations are identical. Extract to shared utility `app/Services/Shared/Support/BooleanParser.php` with `BooleanParser::parse(mixed $value): bool`.
- **Test file**: `tests/Unit/Services/Shared/Support/BooleanParserTest.php`

##### Fix #47: saveValueInSession Accepts Arbitrary Keys
- **File**: `app/Services/Shared/Authentication/SecretManager.php:235`
- **Problem**: `saveValueInSession(string $key, string $value)` stores any key in the session with no validation.
- **Fix**: Add an allowlist of valid keys. Throw `\InvalidArgumentException` for keys not in the list.
- **Test file**: `tests/Unit/Services/Shared/Authentication/SecretManagerSessionTest.php`

##### Fix #49: Camt processAmount() Mixes Float/String
- **File**: `app/Services/Camt/Conversion/TransactionMapper.php:738`
- **Problem**: Amount comparison using PHP `>` operator on amount strings, which does lexicographic comparison for strings.
- **Fix**: Use `bccomp()` for all amount comparisons and `bcmul()` for multiplication.
- **Test file**: `tests/Unit/Services/Camt/Conversion/TransactionMapperAmountTest.php`
- **Dependencies**: Coordinates with #68 (same file, same issue).

##### Fix #56: PostCurrencyRequest Empty Method Bodies
- **File**: `app/Services/Shared/Request/PostCurrencyRequest.php:21,39,41`
- **Problem**: `get()`, `put()`, and `delete()` have empty bodies, violating the parent interface contract silently.
- **Fix**: Add `throw new \BadMethodCallException('Method not supported for PostCurrencyRequest')` to each.
- **Test file**: `tests/Unit/Services/Shared/Request/PostCurrencyRequestTest.php`

##### Fix #62: TBank saveCookieHeader Coupled to saveSessionId
- **File**: `app/Services/TBank/Authentication/SecretManager.php:116`
- **Problem**: `saveCookieHeader()` automatically extracts and saves sessionId from the cookie. If the cookie doesn't contain a session ID, it could overwrite an existing valid session ID with empty string.
- **Fix**: Only call `saveSessionId()` if extracted value is non-empty: `if ('' !== $cookieSessionId) { ... }` (already present at line 122-124 -- verify this is correct).
- **Test file**: `tests/Unit/Services/TBank/Authentication/SecretManagerCookieTest.php`

##### Fix #63: TBank getApiToken Misleading Alias
- **File**: `app/Services/TBank/Authentication/SecretManager.php:24`
- **Problem**: Method name `getApiToken` suggests API token but actually returns session ID.
- **Fix**: Rename to `getSessionId()` or add `@deprecated` annotation pointing to the correct method.
- **Test file**: Part of #62 test file.

##### Fix #64: TRC20 processData() Doesn't Clear Previous Data
- **File**: `app/Services/TRC20/Response/GetTransactionsResponse.php:99`
- **Problem**: Calling `processData()` twice appends duplicate transactions.
- **Fix**: Add a guard: `if ($this->processed) { return; }` or clear the collection first.
- **Test file**: `tests/Unit/Services/TRC20/Response/GetTransactionsResponseTest.php`

##### Fix #65: TRC20 current() May Return null
- **File**: `app/Services/TRC20/Response/GetTransactionsResponse.php:114`
- **Problem**: Return type doesn't account for null possibility.
- **Fix**: Update return type to `?Transaction` or `mixed` with null documentation.
- **Test file**: Part of #64 test file.

##### Fix #66: TBank resolveServiceAccountCurrency Accesses ->id on Arrays
- **File**: `app/Services/TBank/Conversion/Routine/TransactionProcessor.php:253`
- **Problem**: Uses `->id` object access syntax on what may be an array.
- **Fix**: Use `$account['id']` array access syntax, with appropriate type checking.
- **Test file**: `tests/Unit/Services/TBank/Conversion/TransactionProcessorCurrencyTest.php`

##### Fix #67: TRC20 $dateFrom Reused from Outer Scope
- **File**: `app/Services/TRC20/Conversion/Routine/TransactionProcessor.php:130,183`
- **Problem**: Variable `$dateFrom` from outer loop scope bleeds into inner logic.
- **Fix**: Use separate variable names for different scopes.
- **Test file**: `tests/Unit/Services/TRC20/Conversion/TransactionProcessorDateScopeTest.php`

##### Fix #68: Camt processAmount Uses > on Amount Strings
- **File**: `app/Services/Camt/Conversion/TransactionMapper.php:707`
- **Problem**: Same issue as #49 -- string comparison for amounts.
- **Fix**: Use `bccomp()`. Combine with #49 fix.
- **Dependencies**: Same file as #49, do together.

##### Fix #69: Duplicated Cleanup Regex in SimpleFIN
- **File**: `app/Services/SimpleFIN/Conversion/TransactionTransformer.php:207,473`
- **Problem**: The cleanup patterns array is defined identically at two locations.
- **Fix**: Extract to a class constant `private const array CLEANUP_PATTERNS = [...]`.
- **Test file**: `tests/Unit/Services/SimpleFIN/Conversion/TransactionTransformerCleanupTest.php`

---

## Wave 4: LOW (19 fixes)

### Phase 4A: Quick Cleanups (parallelizable, 2 agents)

#### Agent: fix-low-cleanup1 (Fixes #70, #71, #72, #73, #74, #75, #76, #77, #78, #79)

##### Fix #70: Hardcoded GPU Fingerprint
- **File**: `app/Services/BasisBank/Auth/BasisBankFormParser.php:35`
- **Current**: `DEVICE_GPU_INFO = 'Firefly Data Importer'`
- **Fix**: Change to a realistic GPU string like `'ANGLE (Intel, Intel(R) UHD Graphics 620, OpenGL ES 3.0)'` or make configurable.
- **Test file**: `tests/Unit/Services/BasisBank/Auth/BasisBankFormParserGpuTest.php`

##### Fix #71: 3 Variants of extractArrayPayload
- **Files**: Multiple BasisBank request files
- **Fix**: Unify into a single shared implementation in `BasisBankWebSessionTrait` (created in #20).
- **Dependencies**: Requires #20

##### Fix #72: fillDeviceInfoFields Mutates by Reference
- **File**: BasisBank form parser
- **Fix**: Return a new array instead of modifying the passed array.
- **Test file**: Part of #70 test file.

##### Fix #73: LunchFlow Response Class Dependency
- **Fix**: Clarify whether the response class should be in Shared or provider-specific namespace.

##### Fix #74: Delete Dead ProgressInformation/CombinedProgressInformation Traits
- **Files**: `app/Services/Shared/Conversion/CombinedProgressInformation.php` and `app/Services/Shared/Conversion/ProgressInformation.php`
- **Problem**: Both are `@deprecated` and have zero usages in the codebase (verified via grep).
- **Fix**: Delete both files.
- **Test file**: No test needed -- deletion of dead code. Verify no references exist.

##### Fix #75: extractCodesFromText Regex Check Fragile
- **Fix**: Use `> 0` instead of truthy check for preg_match result.

##### Fix #76: Unreachable Break in Camt
- **Fix**: Remove the unreachable `break` statement.

##### Fix #77: Camt getAccountId/validAccountInfo Unused
- **Fix**: Verify no callers via grep, then delete if truly dead.

##### Fix #78: TBank Recursive Walker Parent+Child Duplication
- **Fix**: Modify walker to only emit leaf nodes, or dedup parent entries.
- **Test file**: `tests/Unit/Services/TBank/Request/RecursiveWalkerTest.php`

##### Fix #79: TRC20 TronAddressCodec Leading-Zero Dead Code
- **Fix**: Add documentation comment explaining the edge case, or remove the dead branch.

#### Agent: fix-low-cleanup2 (Fixes #80, #81, #82, #83, #84, #85, #86, #87, #88)

##### Fix #80: TBank setConfiguration Wrong @throws
- **Fix**: Update the `@throws` docblock to match actual exceptions.

##### Fix #81: TRC20 breakOnDownload Silent Failure
- **Fix**: Propagate error to caller instead of silently returning.
- **Test file**: `tests/Unit/Services/TRC20/Conversion/BreakOnDownloadTest.php`

##### Fix #82: SimpleFIN $type Uninitialized Risk
- **Fix**: Change to if/else to guarantee initialization.

##### Fix #83: FIXME Comments Unresolved
- **Fix**: Resolve each FIXME or convert to tracked GitHub issues.

##### Fix #84: Nordigen 429 Handler Missing method_exists
- **Fix**: Add `method_exists($e, 'getResponse')` guard before calling it.
- **Test file**: `tests/Unit/Services/Nordigen/RateLimitHandlerTest.php`

##### Fix #85: LunchFlow Pending Filter No-Op
- Same as #54. Will be resolved when #54 is fixed.

##### Fix #86: BasisBankFormParser Alias
- Same as #57. Will be resolved when #57 is fixed.

##### Fix #87: SyncStateManager Dead false === Check
- **File**: `app/Services/Shared/SyncState/SyncStateManager.php:53`
- **Problem**: `Carbon::parse()` never returns `false` -- it throws on invalid input. The `false === $parsed` check at line 53 is dead code.
- **Fix**: Remove the dead check. The try/catch at line 58 already handles parse failures.
- **Test file**: Part of Wave 1 #12 test file.

##### Fix #88: TBank RoutineManager Misleading @throws
- **Fix**: Remove the incorrect `@throws` annotation.

---

## Parallel Agent Execution Plan

### Execution Timeline

```
Time  | Agent Slot 1          | Agent Slot 2          | Agent Slot 3          | Agent Slot 4
------|-----------------------|-----------------------|-----------------------|----------------------
T0    | fix-criticals-batch1  | fix-criticals-batch2  | (wait)                | (wait)
      | #1,3,5,6,7,8         | #4,9,10,14            |                       |
      | IN PROGRESS           | IN PROGRESS           |                       |
------|-----------------------|-----------------------|-----------------------|----------------------
T1    | fix-criticals-security| fix-high-factory-simp | fix-high-lunchflow-so | fix-high-security
      | #11,12,13             | #15,16,38             | #17,18,19             | #37
      | (wait for batch1 on   |                       |                       |
      | #13 if same file)     |                       |                       |
------|-----------------------|-----------------------|-----------------------|----------------------
T2    | fix-high-basisbank-ex | fix-high-shared-ex    | fix-high-trc20-tbank  | fix-high-filesize
      | #20,32,33             | #21,27,28,29,30,31,34 | #22,23,24,25,26       | #35,36
      | (AFTER batch1 done)   | (AFTER batch1+2 done) | (AFTER batch1+2 done) |
------|-----------------------|-----------------------|-----------------------|----------------------
T3    | fix-medium-basisbank  | fix-medium-config     | fix-medium-nordigen-eb| fix-medium-misc
      | #2,42,43,55,57,58,61 | #44,45,46,48,50       | #39,51,52,53,54,59,60 | #40,41,47,49,56,62,
      | (AFTER #20 done)      | (AFTER #27 done)      |                       | 63,64,65,66,67,68,69
------|-----------------------|-----------------------|-----------------------|----------------------
T4    | fix-low-cleanup1      | fix-low-cleanup2      |                       |
      | #70-79                | #80-88                |                       |
```

### Agent Dependencies (Critical Path)

```
fix-criticals-batch1 (#1,3,5,6,7,8)
    |
    +---> fix-criticals-security (#13, same file as #1)
    |
    +---> fix-high-basisbank-extract (#20, uses GetAccountsRequest)
    |         |
    |         +---> fix-high-shared-extract (#21, depends on TRC20/TBank files from batch1+2)
    |         |
    |         +---> fix-medium-basisbank (#2,42,43,55,57,58,61, depends on #20 trait)
    |
    +---> fix-high-trc20-tbank-extract (#22-26, depends on TRC20 files from batch1)

fix-criticals-batch2 (#4,9,10,14)
    |
    +---> fix-high-shared-extract (#21, depends on TBank files from batch2)
    |
    +---> fix-high-trc20-tbank-extract (#22-26, depends on TBank files from batch2)

fix-high-shared-extract (#27)
    |
    +---> fix-medium-config (#44,45,46, depends on #27 refactor)
```

## Testing Strategy

### Test Organization

All new tests go in `tests/Unit/Services/` mirroring the app structure:

```
tests/Unit/Services/
  BasisBank/
    Auth/BasisBankFormParserCheckboxTest.php
    Authentication/SecretManagerEncryptionTest.php
    Request/BasisBankWebSessionTraitTest.php
    Request/SessionRecoveryNoAutoSmsTest.php
    Request/NormalizeDateTest.php
    Request/StatementIdCacheTest.php
    Request/WebSessionRowsCacheTest.php
  Camt/
    Conversion/TransactionMapperAmountTest.php
  CSV/
    Converter/DateMutationTest.php
  EnableBanking/
    Model/TransactionJsonTest.php
  LunchFlow/
    Request/RequestParametersTest.php
  Nordigen/
    RateLimitHandlerTest.php
  Shared/
    Authentication/SecretManagerSessionTest.php
    Configuration/
      ConfigurationCredentialRedactionTest.php
      ConfigurationDateRangeTest.php
      ConfigurationHydrationTest.php
    Conversion/
      AccountMapperCurrencyTest.php
      ConversionRoutineFactoryTest.php
      NormalizeServiceAccountTest.php
      TransactionFilterTraitTest.php
    Model/ImportServiceAccountIbanTest.php
    Request/
      AbstractApiRequestTest.php
      PostCurrencyRequestTest.php
    Status/StatusTrackerTraitTest.php
    Support/
      BooleanParserTest.php
      CurrencyCodeTest.php
      TransactionIdGeneratorTest.php
    SyncState/
      SyncStateManagerMutationTest.php
      SyncStateManagerSecurityTest.php
  SimpleFIN/
    Conversion/TransactionTransformerCleanupTest.php
    SimpleFINServiceLogTest.php
  Sophtron/
    Request/RequestDeadMethodsTest.php
  TBank/
    OAuthClientStateTest.php
    Authentication/SecretManagerCookieTest.php
    Conversion/TransactionProcessorCurrencyTest.php
    Request/SessionJsonRequestTest.php
    Request/RecursiveWalkerTest.php
    Support/CookieParserTest.php
  TRC20/
    Authentication/SecretManagerLogTest.php
    Conversion/TransactionProcessorDateScopeTest.php
    Conversion/BreakOnDownloadTest.php
    Request/
      GetWalletRequestTokenFilterTest.php
      TRC20BaseRequestTest.php
      TRC20QueryBuildTest.php
    Response/GetTransactionsResponseTest.php
```

### Test Pattern

Follow existing test patterns (see `tests/Unit/Services/TBank/GetTransactionsRequestExtractAmountTest.php` and `tests/Unit/Services/Nordigen/TransactionNullDateTest.php`):

1. Extend `Tests\TestCase`
2. Use `ReflectionMethod` for testing private methods
3. Use `@coversDefaultClass` annotation
4. Use descriptive test method names explaining the fix
5. Include fix number in docblock: `Fix #N: description`

### Running Tests

```bash
# Inside docker container:
docker compose exec importer php vendor/bin/phpunit --testsuite Unit

# Or specific test:
docker compose exec importer php vendor/bin/phpunit --filter ConfigurationCredentialRedactionTest
```

## Risks and Mitigations

### Risk 1: Large BasisBank Extraction (#20) Breaks Functionality
- **Severity**: HIGH
- **Mitigation**: Extract one method at a time, run full test suite after each extraction. Start with the simplest methods (getters, formatters) before tackling complex session management methods.

### Risk 2: File Conflicts Between Parallel Agents
- **Severity**: MEDIUM
- **Mitigation**: The dependency graph above prevents parallel writes to the same file. Each agent "owns" its files. Cross-file references (imports) are additive and unlikely to conflict.

### Risk 3: Session Encryption (#37) Breaks Existing Sessions
- **Severity**: MEDIUM
- **Mitigation**: Add fallback decryption: try `Crypt::decryptString()` first, if it throws, assume plaintext legacy value. Log a deprecation warning for legacy values.

### Risk 4: SyncStateManager Encryption (#12) Breaks Existing State Files
- **Severity**: MEDIUM
- **Mitigation**: Same approach as #37: if decryption fails, read as raw JSON (legacy). Re-encrypt on next write cycle.

### Risk 5: ConversionRoutineFactory (#15) -- Spectre/EB Flow Names
- **Severity**: LOW
- **Mitigation**: Verified flow names from `config/importer.php`: Spectre uses `'spectre'`, Enable Banking uses `'eb'`. The factory must match these exact strings.

## New Files Created by This Plan

| File | Created By Fix | Purpose |
|------|----------------|---------|
| `app/Services/BasisBank/Request/BasisBankWebSessionTrait.php` | #20 | Shared web session methods |
| `app/Services/TBank/Support/CookieParser.php` | #23 | Cookie parsing utility |
| `app/Services/TRC20/Request/TRC20BaseRequest.php` | #22 | Shared TRC20 request boilerplate |
| `app/Services/Shared/Request/AbstractApiRequest.php` | #52 | Shared API request base class |
| `app/Services/Shared/Status/StatusTrackerTrait.php` | #28 | Shared error/warning/message tracking |
| `app/Services/Shared/Conversion/TransactionFilterTrait.php` | #53 | Shared transaction filtering |
| `app/Services/Shared/Support/TransactionIdGenerator.php` | #59 | Transaction ID generation utility |
| `app/Services/Shared/Support/BooleanParser.php` | #41 | Boolean parsing utility |
| `app/Services/Shared/Configuration/DateRangeResolver.php` | #34 | Date range calculation logic |
| ~45 new test files | All fixes | Unit tests for each fix |

## Files Deleted by This Plan

| File | Deleted By Fix | Reason |
|------|----------------|--------|
| `app/Services/Shared/Conversion/CombinedProgressInformation.php` | #74 | Dead code, zero usages |
| `app/Services/Shared/Conversion/ProgressInformation.php` | #74 | Dead code, zero usages |

## Success Criteria

- [ ] All 88 bugs fixed (10 in-progress, 78 remaining)
- [ ] Each fix has a corresponding PHPUnit test
- [ ] All existing tests still pass (`php vendor/bin/phpunit --testsuite Unit`)
- [ ] No new duplication introduced (grep verification after each extraction)
- [ ] File sizes reduced: GetAccountsRequest < 800 lines, GetTransactionsRequest < 800 lines, Configuration < 800 lines
- [ ] All credentials redacted from downloadable config JSON
- [ ] SyncStateManager uses LOCK_EX + encryption
- [ ] BasisBank session recovery does not auto-trigger SMS
- [ ] No plaintext credentials in PHP session storage

---

## Key File Paths Referenced

All paths relative to `/mnt/g/REPOS/firefly/data-importer/`:

**Core files modified by multiple fixes:**
- `app/Services/Shared/Configuration/Configuration.php` -- #11, #27, #34, #44, #45, #46
- `app/Services/Shared/SyncState/SyncStateManager.php` -- #12, #48, #87
- `app/Services/BasisBank/Request/GetAccountsRequest.php` -- #1, #13, #20, #32, #58, #61
- `app/Services/BasisBank/Request/GetTransactionsRequest.php` -- #20, #33, #42, #43, #61
- `app/Services/Shared/Conversion/ConversionRoutineFactory.php` -- #15
- `app/Services/BasisBank/Authentication/SecretManager.php` -- #37, #41
- `app/Services/BasisBank/Auth/BasisBankFormParser.php` -- #2, #57, #70
- `app/Services/Nordigen/Model/Transaction.php` -- #6, #7, #9, #59, #60
- `app/Services/Camt/Conversion/TransactionMapper.php` -- #36, #49, #68

**Shared utilities (already exist):**
- `app/Services/Shared/Support/CurrencyCode.php` -- extended by #21, #29
- `app/Services/Shared/Secrets/ProviderSecretStore.php` -- reference pattern for #12, #37
- `app/Services/TRC20/Support/TRC20TokenFilter.php` -- delegated to by #25

**Test infrastructure:**
- `tests/TestCase.php` -- base test class
- `phpunit.xml` -- test configuration
- `tests/Unit/Services/TBank/GetTransactionsRequestExtractAmountTest.php` -- reference test pattern
- `tests/Unit/Services/Nordigen/TransactionNullDateTest.php` -- reference test pattern
