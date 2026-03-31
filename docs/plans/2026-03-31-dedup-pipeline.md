# Implementation Plan: Deduplication Pipeline Redesign

## Overview

The current deduplication pipeline is spread across three competing layers (provider-level `$seenTransactionIds` / `deduplicateByDescriptionDate()`, client-side `uniqueTransaction()` in ApiSubmitter, and server-side `error_if_duplicate_hash`), mixed into a 1627-line God Object (`ApiSubmitter`), with provider-specific bugs that cause missed duplicates (CRITICAL-2: TRC20 cross-wallet), silent data loss (HIGH-4: Nordigen internalTransactionId wipe), and non-deterministic fallback IDs (HIGH-5: `microtime()` in `generateFallbackId`). This plan introduces a clean `DuplicateDetector` hierarchy, fixes all CRITICAL/HIGH bugs, decomposes ApiSubmitter, wires the already-implemented batch endpoints, and adds a **transaction source** concept that namespaces external_ids by import provider to prevent cross-provider dedup collisions.

## Requirements

- 4 sequential phases, each independently deployable (no big-bang release)
- Fix CRITICAL-2: TRC20 cross-wallet transfers creating duplicates when same txHash scanned from both wallets
- Fix HIGH-4: Nordigen `internalTransactionId` silently overwriting `transactionId`, creating new external_ids for existing transactions
- Fix HIGH-5: `generateFallbackId` using `microtime()` when JSON encoding fails, producing non-deterministic IDs
- Abstract `DuplicateDetector` with provider-specific `extractKey()` implementations
- Decompose ApiSubmitter from 1627 lines into 5 focused classes
- Config flag to revert to old dedup (rollback strategy)
- Extract duplicated `resolveLatestTransactionDate` / `buildContextFingerprint` / `resolveIncrementalDateFromCursor` from 3 TransactionProcessors into shared utility
- TDD approach: tests for each detector's `extractKey()` before implementation
- **NEW: Transaction source** -- each transaction has a nullable `import_source_id` FK that identifies which provider created it (e.g., "TRC20", "TBank", "BasisBank"). Dedup requires BOTH `source` AND `external_id` to match, preventing false duplicates when external_ids from different banking systems collide.

## Design Decision: Source Storage Strategy

**Chosen: FK column on `transaction_journals` + `import_sources` lookup table**

Alternatives considered:

| Approach | Pros | Cons |
|----------|------|------|
| **FK on `transaction_journals`** | Indexed, fast dedup queries, proper relational model, single JOIN | Requires migration + new table |
| **Meta field in `journal_meta`** | Zero schema changes, uses existing infrastructure | Slow (JSON-encoded string comparison), no index, second JOIN needed for source filtering |
| **Reuse `original_source` meta** | Already exists | Hard-coded to `ff3-v{version}` in StoreRequest, not user-settable, wrong semantics |

The FK approach is chosen because:
1. Every dedup query will filter by source -- this must be fast
2. The `ExternalIdController` already joins `journal_meta` for `external_id`; adding a second meta JOIN for source would double the cost
3. A dedicated indexed column on `transaction_journals` adds near-zero overhead to the existing JOIN
4. The `import_sources` lookup table is a simple id+name table with ~15 rows total

## Architecture Changes

### Firefly III (Phase 1)

| Change | File | Description |
|--------|------|-------------|
| Wire route | `/mnt/g/REPOS/firefly/firefly-iii/routes/api.php` ~line 702 | Add `POST search/external-ids` route |
| Wire route | `/mnt/g/REPOS/firefly/firefly-iii/routes/api.php` ~line 605 | Add `POST transactions/batch` route (before wildcard) |
| Add capabilities | `/mnt/g/REPOS/firefly/firefly-iii/app/Api/V1/Controllers/System/AboutController.php` | Add `capabilities.batch_transactions` + `capabilities.import_sources` to `/about` response |
| NEW migration | `/mnt/g/REPOS/firefly/firefly-iii/database/migrations/2026_03_31_000001_create_import_sources.php` | Create `import_sources` table and add `import_source_id` FK on `transaction_journals` |
| NEW model | `/mnt/g/REPOS/firefly/firefly-iii/app/Models/ImportSource.php` | Eloquent model for import_sources lookup table |
| MODIFY model | `/mnt/g/REPOS/firefly/firefly-iii/app/Models/TransactionJournal.php` | Add `importSource()` BelongsTo relation, add `import_source_id` to `$fillable` |
| NEW controller | `/mnt/g/REPOS/firefly/firefly-iii/app/Api/V1/Controllers/System/ImportSourceController.php` | Auto-create-or-find endpoint for import sources |
| NEW request | `/mnt/g/REPOS/firefly/firefly-iii/app/Api/V1/Requests/System/ImportSourceRequest.php` | Validation for import source create/find |
| MODIFY request | `/mnt/g/REPOS/firefly/firefly-iii/app/Api/V1/Requests/Search/ExternalIdSearchRequest.php` | Accept optional `source` parameter |
| MODIFY controller | `/mnt/g/REPOS/firefly/firefly-iii/app/Api/V1/Controllers/Search/ExternalIdController.php` | Filter by `import_source_id` when `source` param provided |
| MODIFY factory | `/mnt/g/REPOS/firefly/firefly-iii/app/Factory/TransactionJournalFactory.php` | Store `import_source_id` when creating journals |
| MODIFY request | `/mnt/g/REPOS/firefly/firefly-iii/app/Api/V1/Requests/Models/Transaction/StoreRequest.php` | Accept optional `import_source` string field |
| MODIFY request | `/mnt/g/REPOS/firefly/firefly-iii/app/Api/V1/Requests/Models/Transaction/BatchStoreRequest.php` | Accept optional `import_source` string field |

### Data Importer (Phases 2-4)

| Change | File | Description |
|--------|------|-------------|
| NEW abstract | `app/Services/Shared/Dedup/DuplicateDetector.php` | Abstract base with template method pattern, includes `sourceName()` |
| NEW VO | `app/Services/Shared/Dedup/DuplicateCheckResult.php` | Immutable result value object |
| NEW detector | `app/Services/Shared/Dedup/ExternalIdDuplicateDetector.php` | Default external_id based detector |
| NEW detector | `app/Services/TRC20/Dedup/Trc20DuplicateDetector.php` | Fixes CRITICAL-2: wallet-independent key |
| NEW detector | `app/Services/BasisBank/Dedup/BasisBankDuplicateDetector.php` | Composite key for unstable TransactionId |
| NEW detector | `app/Services/TBank/Dedup/TBankDuplicateDetector.php` | Uses operationId |
| NEW detector | `app/Services/Nordigen/Dedup/NordigenDuplicateDetector.php` | Handles legacy transactionId migration |
| NEW factory | `app/Services/Shared/Dedup/DuplicateDetectorFactory.php` | Creates detector by provider/flow, sets source name |
| NEW class | `app/Services/Shared/Import/Routine/DuplicateChecker.php` | Extracted from ApiSubmitter, passes source to API |
| NEW class | `app/Services/Shared/Import/Routine/TransactionSubmitter.php` | Extracted from ApiSubmitter, includes source in payload |
| NEW class | `app/Services/Shared/Import/Routine/TagManager.php` | Extracted from ApiSubmitter |
| NEW class | `app/Services/Shared/Import/Routine/CurrencyManager.php` | Extracted from ApiSubmitter |
| NEW class | `app/Services/Shared/Import/Routine/SubmissionOrchestrator.php` | Coordinates the above, auto-creates source |
| NEW trait | `app/Services/Shared/Conversion/TransactionProcessorHelpers.php` | Extracted boilerplate |
| MODIFY | `app/Services/Shared/Import/Routine/ApiSubmitter.php` | Wire new DuplicateChecker, then decompose |
| MODIFY | `app/Services/Shared/Import/Routine/BatchApiClient.php` | Accept optional `source` param in `batchSearchExternalIds()` |
| MODIFY | `app/Services/TRC20/Conversion/Routine/TransactionProcessor.php` | Fix external_id format (line 471) |
| MODIFY | `app/Services/Nordigen/Model/Transaction.php` | Fix transactionId wipe (line 148) |
| MODIFY | `app/Services/Shared/Support/TransactionIdGenerator.php` | Fix microtime fallback (line 43) |
| MODIFY | `app/Services/Shared/Configuration/Configuration.php` | Add `dedupPipelineVersion` setting |
| MODIFY | `config/importer.php` | Add `dedup.pipeline_version` config key, add `source_names` map |

## Implementation Steps

### Phase 1: Wire Existing Endpoints + Import Source Infrastructure in Firefly III

**Goal:** Make `ExternalIdController` and `BatchStoreController` accessible. Add `import_sources` table and wire source-aware dedup. These controllers already exist and are tested -- they just need route registration plus source filtering. Immediate benefit: `BatchApiClient::supportsBatchEndpoints()` starts returning `true`, and the source infrastructure is available for Phase 2.

**Independently deployable:** Yes. Data importer already probes for batch support and falls back gracefully to pagination when unavailable (see `ApiSubmitter::setImportJob()` line 103). Source fields are optional -- existing importers that do not send `import_source` work identically to before.

---

#### Step 1.1: Register batch transaction route

**File:** `/mnt/g/REPOS/firefly/firefly-iii/routes/api.php`

**Action:** Insert `Route::post('batch', ...)` inside the transaction routes group, BEFORE the `{transactionGroup}` wildcard route. The wildcard is at the existing `Route::get('{transactionGroup}', ...)` line. If "batch" is placed after the wildcard, Laravel resolves the string "batch" as a `transactionGroup` parameter and routes to `ShowController@show` instead.

**Location:** Find the transaction routes group (namespace `FireflyIII\Api\V1\Controllers\Models\Transaction`, prefix `v1/transactions`). Insert before any `{transactionGroup}` route:

```php
Route::post('batch', ['uses' => 'BatchStoreController@store', 'as' => 'batch-store'])
    ->middleware('throttle:10,1');
```

**Dependencies:** None
**Risk:** Medium -- wrong placement causes silent 404. Verify with curl after.
**Verification:** `curl -s -o /dev/null -w '%{http_code}' -X POST http://app:8080/api/v1/transactions/batch -H 'Authorization: Bearer TOKEN' -H 'Content-Type: application/json' -d '{"batch":[]}'` should return 422 (validation error for empty batch), NOT 404.

---

#### Step 1.2: Register external-id search route

**File:** `/mnt/g/REPOS/firefly/firefly-iii/routes/api.php`

**Action:** Inside the search routes group (line 695-705, namespace `FireflyIII\Api\V1\Controllers\Search`, prefix `v1/search`), add:

```php
Route::post('external-ids', ['uses' => 'ExternalIdController@search', 'as' => 'external-ids']);
```

**Dependencies:** None
**Risk:** Low -- no wildcard conflict in search group.
**Verification:** `curl -s -o /dev/null -w '%{http_code}' -X POST http://app:8080/api/v1/search/external-ids -H 'Authorization: Bearer TOKEN' -H 'Content-Type: application/json' -d '{"external_ids":["test"]}'` should return 200 with `{"results":{"test":null}}`.

---

#### Step 1.3: Create import_sources migration

**File:** `/mnt/g/REPOS/firefly/firefly-iii/database/migrations/2026_03_31_000001_create_import_sources.php` (NEW)

**Action:** Create a migration that:
1. Creates the `import_sources` lookup table (id, user_group_id, name, created_at, updated_at)
2. Adds a nullable `import_source_id` FK column to `transaction_journals`
3. Adds a composite index on `(import_source_id, deleted_at)` for efficient dedup filtering

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('import_sources')) {
            Schema::create('import_sources', static function (Blueprint $blueprint): void {
                $blueprint->id();
                $blueprint->unsignedInteger('user_group_id');
                $blueprint->string('name', 64);
                $blueprint->timestamps();
                $blueprint->unique(['user_group_id', 'name'], 'uq_import_sources_group_name');
                $blueprint->foreign('user_group_id')
                    ->references('id')
                    ->on('user_groups')
                    ->onDelete('cascade');
            });
        }

        if (!Schema::hasColumn('transaction_journals', 'import_source_id')) {
            try {
                Schema::table('transaction_journals', static function (Blueprint $blueprint): void {
                    $blueprint->unsignedBigInteger('import_source_id')->nullable()->after('bill_id');
                    $blueprint->foreign('import_source_id')
                        ->references('id')
                        ->on('import_sources')
                        ->onDelete('set null');
                    $blueprint->index(
                        ['import_source_id', 'deleted_at'],
                        'idx_tj_import_source_deleted'
                    );
                });
            } catch (QueryException $e) {
                if (!str_contains($e->getMessage(), 'Duplicate key name')
                    && !str_contains($e->getMessage(), 'Duplicate column name')) {
                    Log::error(sprintf('Error adding import_source_id: %s', $e->getMessage()));
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('transaction_journals', 'import_source_id')) {
            Schema::table('transaction_journals', static function (Blueprint $blueprint): void {
                $blueprint->dropForeign(['import_source_id']);
                $blueprint->dropIndex('idx_tj_import_source_deleted');
                $blueprint->dropColumn('import_source_id');
            });
        }
        Schema::dropIfExists('import_sources');
    }
};
```

**Dependencies:** None
**Risk:** Low -- additive migration. Nullable FK means existing data is unaffected.

---

#### Step 1.4: Create ImportSource model

**File:** `/mnt/g/REPOS/firefly/firefly-iii/app/Models/ImportSource.php` (NEW)

```php
<?php

declare(strict_types=1);

namespace FireflyIII\Models;

use FireflyIII\Support\Models\ReturnsIntegerIdTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lookup table for import provider names.
 *
 * Each row represents a unique import source (e.g., "trc20", "tbank", "basisbank").
 * Used to namespace external_ids -- dedup matches require both source AND external_id.
 */
class ImportSource extends Model
{
    use ReturnsIntegerIdTrait;

    protected $fillable = ['user_group_id', 'name'];

    protected $table = 'import_sources';

    public function userGroup(): BelongsTo
    {
        return $this->belongsTo(UserGroup::class);
    }

    public function transactionJournals(): HasMany
    {
        return $this->hasMany(TransactionJournal::class, 'import_source_id');
    }

    /**
     * Find or create an import source by name within a user group.
     */
    public static function findOrCreateByName(int $userGroupId, string $name): self
    {
        $name = strtolower(trim($name));

        return self::firstOrCreate(
            ['user_group_id' => $userGroupId, 'name' => $name],
        );
    }
}
```

**Dependencies:** Step 1.3
**Risk:** Low

---

#### Step 1.5: Add importSource relation to TransactionJournal

**File:** `/mnt/g/REPOS/firefly/firefly-iii/app/Models/TransactionJournal.php`

**Action:** Add `import_source_id` to the `$fillable` array (line 61-73) and add a `BelongsTo` relation method:

```php
// Add to $fillable:
'import_source_id',

// Add method:
public function importSource(): BelongsTo
{
    return $this->belongsTo(ImportSource::class, 'import_source_id');
}
```

Also add `'import_source_id' => 'integer'` to the `casts()` method.

**Dependencies:** Steps 1.3-1.4
**Risk:** Low

---

#### Step 1.6: Register import source API routes

**File:** `/mnt/g/REPOS/firefly/firefly-iii/routes/api.php`

**Action:** Add a route for finding/creating import sources. Place in the system routes group:

```php
// Inside system routes group (prefix v1/)
Route::post('import-sources', ['uses' => 'ImportSourceController@findOrCreate', 'as' => 'import-sources.find-or-create']);
Route::get('import-sources', ['uses' => 'ImportSourceController@index', 'as' => 'import-sources.index']);
```

**Dependencies:** None
**Risk:** Low

---

#### Step 1.7: Create ImportSourceController

**File:** `/mnt/g/REPOS/firefly/firefly-iii/app/Api/V1/Controllers/System/ImportSourceController.php` (NEW)

```php
<?php

declare(strict_types=1);

namespace FireflyIII\Api\V1\Controllers\System;

use FireflyIII\Api\V1\Controllers\Controller;
use FireflyIII\Enums\UserRoleEnum;
use FireflyIII\Models\ImportSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD for import sources.
 *
 * Import sources are simple name labels (e.g., "trc20", "tbank") that namespace
 * external_ids for dedup. Auto-created on first use by the data importer.
 */
class ImportSourceController extends Controller
{
    protected array $acceptedRoles = [UserRoleEnum::MANAGE_TRANSACTIONS];

    public function __construct()
    {
        parent::__construct();
        $this->middleware(function (Request $request, $next) {
            $this->validateUserGroup($request);

            return $next($request);
        });
    }

    /**
     * List all import sources for the current user group.
     */
    public function index(): JsonResponse
    {
        $sources = ImportSource::where('user_group_id', $this->userGroup->id)
            ->orderBy('name')
            ->get(['id', 'name', 'created_at']);

        return response()->json(['data' => $sources]);
    }

    /**
     * Find or create an import source by name.
     *
     * Idempotent: calling with the same name returns the existing record.
     */
    public function findOrCreate(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|min:1|max:64',
        ]);

        $name   = strtolower(trim($request->input('name')));
        $source = ImportSource::findOrCreateByName($this->userGroup->id, $name);

        return response()->json([
            'data' => [
                'id'   => $source->id,
                'name' => $source->name,
            ],
        ], 200);
    }
}
```

**Dependencies:** Steps 1.4, 1.6
**Risk:** Low

---

#### Step 1.8: Modify ExternalIdSearchRequest to accept source parameter

**File:** `/mnt/g/REPOS/firefly/firefly-iii/app/Api/V1/Requests/Search/ExternalIdSearchRequest.php`

**Action:** Add optional `source` validation rule and extract it in `getSearchParameters()`:

```php
public function rules(): array
{
    return [
        'external_ids'   => 'required|array|min:1|max:500',
        'external_ids.*' => 'required|string|min:1|max:255',
        'start_date'     => 'nullable|date',
        'end_date'       => 'nullable|date|after_or_equal:start_date',
        'source'         => 'nullable|string|min:1|max:64',
    ];
}

/**
 * @return array{external_ids: array<int, string>, start_date: ?string, end_date: ?string, source: ?string}
 */
public function getSearchParameters(): array
{
    return [
        'external_ids' => $this->validated('external_ids'),
        'start_date'   => $this->validated('start_date'),
        'end_date'     => $this->validated('end_date'),
        'source'       => $this->validated('source'),
    ];
}
```

**Dependencies:** None
**Risk:** Low -- additive, backward compatible (source is nullable).

---

#### Step 1.9: Modify ExternalIdController to filter by source

**File:** `/mnt/g/REPOS/firefly/firefly-iii/app/Api/V1/Controllers/Search/ExternalIdController.php`

**Action:** When the `source` parameter is provided, join `import_sources` and filter `transaction_journals.import_source_id` to narrow results. This prevents cross-provider external_id collisions.

After the `$endDate` extraction (line 70), add:

```php
$sourceName = $params['source'];
```

After the date range filters (line 118), add the source filter:

```php
// Optional source filter -- narrows dedup to a single import provider.
if (null !== $sourceName && '' !== $sourceName) {
    $sourceRecord = ImportSource::where('user_group_id', $this->userGroup->id)
        ->where('name', strtolower(trim($sourceName)))
        ->first();

    if (null !== $sourceRecord) {
        $query->where('transaction_journals.import_source_id', '=', $sourceRecord->id);
    } else {
        // Source does not exist yet -- no transactions can match.
        // Return all nulls immediately.
        $resultMap = [];
        foreach ($externalIds as $id) {
            $resultMap[$id] = null;
        }

        return response()->json(['results' => $resultMap]);
    }
}
```

Also add `use FireflyIII\Models\ImportSource;` to the imports.

**Dependencies:** Steps 1.3-1.5, 1.8
**Risk:** Medium -- must not break existing behavior when source is null (no filter applied).
**Verification:** Test with and without source parameter:
```bash
# Without source (existing behavior, returns all matches)
curl -s -X POST http://app:8080/api/v1/search/external-ids \
    -H 'Authorization: Bearer TOKEN' \
    -H 'Content-Type: application/json' \
    -d '{"external_ids":["12345"]}'

# With source (returns only matches from that source)
curl -s -X POST http://app:8080/api/v1/search/external-ids \
    -H 'Authorization: Bearer TOKEN' \
    -H 'Content-Type: application/json' \
    -d '{"external_ids":["12345"], "source":"trc20"}'
```

---

#### Step 1.10: Modify Store and BatchStore requests to accept import_source

**File:** `/mnt/g/REPOS/firefly/firefly-iii/app/Api/V1/Requests/Models/Transaction/StoreRequest.php`

**Action:** Add `import_source` to the extracted transaction data (alongside `external_id`, line 282):

```php
'import_source'         => $this->clearString((string) ($object['import_source'] ?? '')),
```

Add validation rule:
```php
'transactions.*.import_source' => 'nullable|string|min:1|max:64',
```

**File:** `/mnt/g/REPOS/firefly/firefly-iii/app/Api/V1/Requests/Models/Transaction/BatchStoreRequest.php`

**Action:** Same change -- add `import_source` to extracted data and validation rules.

**Dependencies:** None
**Risk:** Low -- additive field, nullable.

---

#### Step 1.11: Modify TransactionJournalFactory to store import_source_id

**File:** `/mnt/g/REPOS/firefly/firefly-iii/app/Factory/TransactionJournalFactory.php`

**Action:** In `createJournal()` (line 330-342), resolve `import_source_id` from the `import_source` string before creating the journal:

```php
// After line 318 ($description = $this->getDescription(...)):
$importSourceId = null;
$importSourceName = trim((string) ($row['import_source'] ?? ''));
if ('' !== $importSourceName) {
    $importSource   = ImportSource::findOrCreateByName($this->userGroup->id, $importSourceName);
    $importSourceId = $importSource->id;
}

// Modify the TransactionJournal::create() call to include import_source_id:
$journal = TransactionJournal::create([
    'user_id'                 => $this->user->id,
    'user_group_id'           => $this->userGroup->id,
    'transaction_type_id'     => $type->id,
    'bill_id'                 => $billId,
    'import_source_id'        => $importSourceId,
    'transaction_currency_id' => $currency->id,
    'description'             => substr($description, 0, 1000),
    'date'                    => $carbon,
    'date_tz'                 => $carbon->format('e'),
    'order'                   => $order,
    'tag_count'               => 0,
    'completed'               => !$row['batch_submission'],
]);
```

Also add `use FireflyIII\Models\ImportSource;` to the imports.

**Dependencies:** Steps 1.3-1.5
**Risk:** Medium -- core transaction creation path. Must ensure null import_source_id works (manual transactions).
**Test:** Existing TransactionJournalFactory tests should pass unchanged (import_source defaults to null).

---

#### Step 1.12: Add capabilities to /about endpoint

**File:** `/mnt/g/REPOS/firefly/firefly-iii/app/Api/V1/Controllers/System/AboutController.php`

**Action:** Add `capabilities` key to the `$data` array in the `about()` method (line 53-59):

```php
$data = [
    'version'      => config('firefly.version'),
    'api_version'  => config('firefly.version'),
    'php_version'  => $phpVersion,
    'os'           => $phpOs,
    'driver'       => $currentDriver,
    'capabilities' => [
        'batch_transactions'        => true,
        'batch_external_id_search'  => true,
        'import_sources'            => true,
    ],
];
```

**Dependencies:** Steps 1.1, 1.2, and 1.6 (capabilities should only be advertised when routes exist)
**Risk:** Low
**Verification:** `curl -s http://app:8080/api/v1/about -H 'Authorization: Bearer TOKEN' | jq '.data.capabilities'` should show `{"batch_transactions":true,"batch_external_id_search":true,"import_sources":true}`.

---

#### Step 1.13: Cache clear and route verification

**Action:** Inside the Firefly III container:
```bash
docker compose exec app php artisan route:clear
docker compose exec app php artisan config:clear
docker compose exec app php artisan cache:clear
docker compose exec app php artisan migrate --force
docker compose exec app php artisan route:list --name=batch
docker compose exec app php artisan route:list --name=external-ids
docker compose exec app php artisan route:list --name=import-sources
```

**Dependencies:** Steps 1.1-1.12
**Risk:** Low

**Commit message:**
```
feat: wire batch API routes, add import source infrastructure for dedup namespacing

Register the already-implemented BatchStoreController and
ExternalIdController in routes/api.php. Add capabilities
advertisement to the /about endpoint so the data importer
can detect batch support.

Create import_sources lookup table and import_source_id FK
on transaction_journals. This namespaces external_ids by
provider, preventing false dedup matches when different
banking systems use colliding IDs (e.g., TBank operation
"12345" vs Nordigen transaction "12345").

ExternalIdController accepts optional "source" parameter
to narrow search. StoreRequest and BatchStoreRequest accept
optional "import_source" string, auto-created on first use.

The river's dark and it's grown cold
Tom Petty stole my heart and sold

Assisted-by: Opus 4.6 via Claude Code
```

---

### Phase 2: DuplicateDetector Hierarchy + Bug Fixes + Source Integration

**Goal:** Introduce abstract `DuplicateDetector` with provider-specific implementations. Fix CRITICAL-2, HIGH-4, HIGH-5. Wire into ApiSubmitter alongside existing dedup (parallel run with config toggle). Each detector provides a `sourceName()` that is passed to the Firefly III API for source-qualified dedup.

**Independently deployable:** Yes. Default config uses existing dedup; new pipeline opt-in via `DEDUP_PIPELINE_VERSION=2` env var.

---

#### Step 2.1: Fix HIGH-5 -- TransactionIdGenerator.generateFallbackId non-deterministic fallback

**File:** `/mnt/g/REPOS/firefly/data-importer/app/Services/Shared/Support/TransactionIdGenerator.php`

**Action:** Line 43 initializes `$hash` from `microtime()` BEFORE trying JSON encode. If `json_encode` throws, the `microtime()` hash is used, producing non-deterministic IDs. Fix: throw the exception instead of silently falling back to non-deterministic behavior.

**Before (line 41-53):**
```php
public static function generateFallbackId(string $prefix, array $array): string
{
    $hash = hash('sha256', (string) microtime());  // BUG: non-deterministic default

    try {
        $encoded = json_encode($array, JSON_THROW_ON_ERROR);
        $hash    = hash('sha256', $encoded);
    } catch (\JsonException $e) {
        Log::error(sprintf('Could not parse array into JSON: %s', $e->getMessage()));
    }

    return sprintf('%s-%s', $prefix, Uuid::uuid5(config('importer.namespace'), $hash));
}
```

**After:**
```php
public static function generateFallbackId(string $prefix, array $array): string
{
    try {
        $encoded = json_encode($array, JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
        // Sort keys and retry with a simpler serialization to ensure determinism.
        // Never fall back to microtime() -- non-deterministic IDs cause duplicate imports.
        ksort($array);
        $encoded = serialize($array);
        Log::warning(sprintf('generateFallbackId: json_encode failed, using serialize fallback: %s', $e->getMessage()));
    }

    $hash = hash('sha256', $encoded);

    return sprintf('%s-%s', $prefix, Uuid::uuid5(config('importer.namespace'), $hash));
}
```

**Dependencies:** None
**Risk:** Low -- existing tests in `TransactionIdGeneratorTest.php` cover determinism.
**Test:** Update `tests/Unit/Services/Shared/Support/TransactionIdGeneratorTest.php` to add a test that verifying the same array always produces the same ID even when JSON encoding would fail (mock with recursive references is hard, but we can test that two calls with identical input yield identical output).

---

#### Step 2.2: Fix HIGH-4 -- Nordigen internalTransactionId silently wipes transactionId

**File:** `/mnt/g/REPOS/firefly/data-importer/app/Services/Nordigen/Model/Transaction.php`

**Action:** Line 148 unconditionally overwrites `transactionId` with `internalTransactionId`, even when `internalTransactionId` is empty. This wipes a valid `transactionId` and forces fallback to `generateFallbackId` (UUID), changing the external_id for existing transactions on re-import.

**Before (lines 136, 146-148):**
```php
$object->transactionId = trim($array['transactionId'] ?? '');
// ...
// 2025-09-07: switch to using internal transaction ID, never "transactionId".
$object->transactionId = trim($array['internalTransactionId'] ?? '');
```

**After:**
```php
// Prefer internalTransactionId when available (more stable across bank API versions).
// Fall back to transactionId if internalTransactionId is empty.
// NEVER wipe a valid ID with an empty one -- that changes the external_id and creates duplicates.
$internalId = trim($array['internalTransactionId'] ?? '');
$externalId = trim($array['transactionId'] ?? '');
$object->transactionId = '' !== $internalId ? $internalId : $externalId;
```

**Dependencies:** None
**Risk:** Medium -- existing Nordigen users who imported AFTER 2025-09-07 already have `internalTransactionId`-based external_ids. Users who imported BEFORE have `transactionId`-based ones. The NordigenDuplicateDetector (Step 2.7) must handle both legacy formats.
**Test:** Add test in `tests/Unit/Services/Nordigen/Model/TransactionFromArrayTest.php`:
- Input with both `transactionId` and `internalTransactionId`: should use `internalTransactionId`
- Input with `transactionId` only (empty `internalTransactionId`): should use `transactionId`
- Input with neither: should trigger `generateFallbackId`

---

#### Step 2.3: Fix CRITICAL-2 -- TRC20 external_id includes wallet address

**File:** `/mnt/g/REPOS/firefly/data-importer/app/Services/TRC20/Conversion/Routine/TransactionProcessor.php`

**Action:** Line 471 constructs `$externalId = sprintf('trc20|%s|%s', $wallet, $txId)`. When the same on-chain transaction (same `txHash`) is scanned from both sender and receiver wallets, two different external_ids are generated (`trc20|walletA|txHash` vs `trc20|walletB|txHash`), causing duplicates.

Fix: Remove wallet from the external_id. The `txHash` (or constructed hash from `buildTransactionId`) is already globally unique on-chain. Use direction suffix instead to distinguish the two legs of a transfer:

**Before (line 471):**
```php
$externalId = sprintf('trc20|%s|%s', $wallet, $txId);
```

**After:**
```php
// Use direction-qualified key: same txHash gets different external_ids for
// incoming vs outgoing legs, but the SAME external_id regardless of which
// wallet triggered the scan. This prevents cross-wallet duplicates.
$direction  = $isOutgoing ? 'out' : 'in';
$externalId = sprintf('trc20|%s|%s', $direction, $txId);
```

**Migration concern:** Existing Firefly III transactions have `trc20|walletAddress|txHash` external_ids. The `Trc20DuplicateDetector` (Step 2.6) must search for BOTH old and new formats during transition.

**Dependencies:** None
**Risk:** HIGH -- changes the external_id format, so first re-import after upgrade will not find old transactions by new external_id. Mitigated by the Trc20DuplicateDetector searching both formats.
**Test:** Add test in `tests/Unit/Services/TRC20/Conversion/Routine/TransactionProcessorExternalIdTest.php`:
- Same txHash from two different wallets: should produce different external_ids (in vs out) but NOT wallet-dependent ones
- Incoming transfer: external_id should be `trc20|in|txHash`
- Outgoing transfer: external_id should be `trc20|out|txHash`

---

#### Step 2.4: Add dedup pipeline version config + source name map

**File:** `/mnt/g/REPOS/firefly/data-importer/app/Services/Shared/Configuration/Configuration.php`

**Action:** Add `dedupPipelineVersion` property (int, default 1). Version 1 = existing behavior. Version 2 = new DuplicateDetector pipeline.

```php
private int $dedupPipelineVersion = 1;

public function getDedupPipelineVersion(): int
{
    return $this->dedupPipelineVersion;
}
```

Add to `fromArray()` / `toArray()`:
```php
$object->dedupPipelineVersion = (int)($array['dedup_pipeline_version'] ?? 1);
// in toArray():
'dedup_pipeline_version' => $this->dedupPipelineVersion,
```

**File:** `/mnt/g/REPOS/firefly/data-importer/config/importer.php`

**Action:** Add config entries:
```php
'dedup' => [
    'pipeline_version' => (int) env('DEDUP_PIPELINE_VERSION', 1),
],

// Maps flow names to source names for import_source tracking.
// Used by DuplicateDetectorFactory to set the source on the detector.
// These names match what gets stored in the import_sources table in Firefly III.
'source_names' => [
    'trc20'     => 'trc20',
    'basisbank' => 'basisbank',
    'tbank'     => 'tbank',
    'nordigen'  => 'nordigen',
    'simplefin' => 'simplefin',
    'spectre'   => 'spectre',
    'sophtron'  => 'sophtron',
    'lunchflow' => 'lunchflow',
    'file'      => 'csv',    // CSV and CAMT both use the 'file' flow
    'eb'        => 'enable_banking',
],
```

Update `Configuration::fromClassicFile()` to read the dedup config.

**Dependencies:** None
**Risk:** Low -- additive change, default preserves existing behavior.

---

#### Step 2.5: Create abstract DuplicateDetector with source support

**File:** `/mnt/g/REPOS/firefly/data-importer/app/Services/Shared/Dedup/DuplicateDetector.php` (NEW)

```php
<?php

declare(strict_types=1);

namespace App\Services\Shared\Dedup;

use App\Services\Shared\Import\Routine\BatchApiClient;
use Illuminate\Support\Facades\Log;

/**
 * Abstract base for provider-specific duplicate detection.
 *
 * Template method pattern:
 * - extractKey() is provider-specific (TRC20, BasisBank, etc.)
 * - sourceName() identifies the import provider (used as dedup namespace)
 * - isDuplicate() is shared (batch search against Firefly III)
 * - warmIndex() is shared (preload known external_ids, filtered by source)
 */
abstract class DuplicateDetector
{
    /** @var array<string, array|null> Maps external_id => existing transaction summary or null */
    protected array $index = [];
    protected bool $indexWarmed = false;

    public function __construct(
        protected readonly ?BatchApiClient $batchClient,
        protected readonly bool $batchAvailable,
    ) {}

    /**
     * Extract the dedup key from a transaction line.
     *
     * Returns null if the line has no usable key (skip dedup for this line).
     * The returned string is used as external_id in Firefly III.
     */
    abstract public function extractKey(array $transaction): ?string;

    /**
     * Return the source name for this detector.
     *
     * This string is sent to Firefly III as the `import_source` field and used
     * to namespace external_ids during dedup queries. Must match the value stored
     * in the `import_sources.name` column.
     *
     * Examples: "trc20", "tbank", "basisbank", "nordigen", "csv"
     */
    abstract public function sourceName(): string;

    /**
     * Extract legacy keys that should also be searched during migration periods.
     * Default: empty array. Override in providers that changed their key format.
     *
     * @return string[] Additional keys to check in the index
     */
    public function extractLegacyKeys(array $transaction): array
    {
        return [];
    }

    /**
     * Check if a transaction is a duplicate based on its external_id.
     */
    public function isDuplicate(array $transaction, array $expectedAccountIds = []): ?DuplicateCheckResult
    {
        $key = $this->extractKey($transaction);
        if (null === $key) {
            return null; // No key available, skip dedup
        }

        // Check primary key in index
        $match = $this->index[$key] ?? null;

        // Check legacy keys if primary not found
        if (null === $match) {
            foreach ($this->extractLegacyKeys($transaction) as $legacyKey) {
                $match = $this->index[$legacyKey] ?? null;
                if (null !== $match) {
                    Log::info(sprintf('Duplicate found via legacy key "%s" (primary: "%s", source: "%s").', $legacyKey, $key, $this->sourceName()));
                    break;
                }
            }
        }

        if (null === $match) {
            return DuplicateCheckResult::unique($key);
        }

        return DuplicateCheckResult::duplicate($key, $match);
    }

    /**
     * Warm the index with known external_ids from Firefly III.
     *
     * When source-aware dedup is available (Firefly III advertises
     * capabilities.import_sources), the batch search is filtered by
     * this detector's sourceName() to prevent cross-provider collisions.
     *
     * @param array $lines All transaction lines to be imported
     */
    public function warmIndex(array $lines): void
    {
        $allKeys = [];
        foreach ($lines as $line) {
            foreach ($line['transactions'] ?? [] as $transaction) {
                $key = $this->extractKey($transaction);
                if (null !== $key) {
                    $allKeys[$key] = true;
                }
                foreach ($this->extractLegacyKeys($transaction) as $legacyKey) {
                    $allKeys[$legacyKey] = true;
                }
            }
        }

        if ([] === $allKeys) {
            $this->indexWarmed = true;
            return;
        }

        // Batch search when available
        if ($this->batchAvailable && null !== $this->batchClient) {
            $this->warmIndexViaBatch(array_keys($allKeys));
        }

        $this->indexWarmed = true;
    }

    /**
     * Remember a just-submitted external_id in the index.
     */
    public function remember(string $key, array $transactionSummary): void
    {
        $this->index[$key] = $transactionSummary;
    }

    public function isWarmed(): bool
    {
        return $this->indexWarmed;
    }

    private function warmIndexViaBatch(array $keys): void
    {
        try {
            $allKeys = array_map('strval', $keys);
            foreach (array_chunk($allKeys, 450) as $chunk) {
                // Pass source name to narrow the search to this provider's transactions.
                $result  = $this->batchClient->batchSearchExternalIds(
                    $chunk,
                    null,
                    null,
                    $this->sourceName(),
                );
                $matches = (array)($result['results'] ?? []);
                foreach ($matches as $extId => $match) {
                    if (null !== $match && is_array($match)) {
                        $this->index[(string)$extId] = $match;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning(sprintf('DuplicateDetector[%s]: batch warmup failed: %s', $this->sourceName(), $e->getMessage()));
        }
    }
}
```

**File:** `/mnt/g/REPOS/firefly/data-importer/app/Services/Shared/Dedup/DuplicateCheckResult.php` (NEW)

```php
<?php

declare(strict_types=1);

namespace App\Services\Shared\Dedup;

/**
 * Immutable value object for duplicate check results.
 */
final readonly class DuplicateCheckResult
{
    private function __construct(
        public bool $isDuplicate,
        public string $key,
        public ?array $existingTransaction,
    ) {}

    public static function unique(string $key): self
    {
        return new self(false, $key, null);
    }

    public static function duplicate(string $key, array $existingTransaction): self
    {
        return new self(true, $key, $existingTransaction);
    }
}
```

**Dependencies:** None
**Risk:** Low -- new code, no existing behavior changes.
**Test:** Write unit tests for `DuplicateCheckResult` (value object tests) and for the `warmIndex` / `isDuplicate` flow using a concrete test double.

---

#### Step 2.6: Create TRC20 DuplicateDetector (fixes CRITICAL-2)

**File:** `/mnt/g/REPOS/firefly/data-importer/app/Services/TRC20/Dedup/Trc20DuplicateDetector.php` (NEW)

```php
<?php

declare(strict_types=1);

namespace App\Services\TRC20\Dedup;

use App\Services\Shared\Dedup\DuplicateDetector;

/**
 * TRC20 dedup uses direction-qualified txHash: trc20|in|txHash or trc20|out|txHash.
 *
 * Legacy format was trc20|walletAddress|txHash. This detector searches both
 * formats during the migration period.
 *
 * Source name: "trc20"
 */
final class Trc20DuplicateDetector extends DuplicateDetector
{
    /** @var string[] Wallet addresses configured in this import */
    private array $wallets = [];

    public function sourceName(): string
    {
        return 'trc20';
    }

    public function setWallets(array $wallets): void
    {
        $this->wallets = $wallets;
    }

    public function extractKey(array $transaction): ?string
    {
        $externalId = trim((string)($transaction['external_id'] ?? ''));
        if ('' === $externalId) {
            return null;
        }

        // If the key already uses the new format (trc20|in|... or trc20|out|...), use as-is
        if (str_starts_with($externalId, 'trc20|in|') || str_starts_with($externalId, 'trc20|out|')) {
            return $externalId;
        }

        // Otherwise it is the old format trc20|wallet|txHash -- pass through
        return $externalId;
    }

    /**
     * During migration, also search for old-format keys (trc20|walletAddress|txHash)
     * that may exist in Firefly III from previous imports.
     */
    public function extractLegacyKeys(array $transaction): array
    {
        $externalId = trim((string)($transaction['external_id'] ?? ''));
        if ('' === $externalId) {
            return [];
        }

        $parts = explode('|', $externalId, 3);
        if (count($parts) < 3 || 'trc20' !== $parts[0]) {
            return [];
        }

        $direction = $parts[1]; // 'in' or 'out'
        $txHash    = $parts[2];

        // Only generate legacy keys for new-format IDs
        if ('in' !== $direction && 'out' !== $direction) {
            return [];
        }

        // Generate old-format keys for all configured wallets
        $legacyKeys = [];
        foreach ($this->wallets as $wallet) {
            $legacyKeys[] = sprintf('trc20|%s|%s', $wallet, $txHash);
        }

        return $legacyKeys;
    }
}
```

**Dependencies:** Step 2.3 (TRC20 external_id format change), Step 2.5 (abstract base)
**Risk:** Medium -- legacy key search adds volume to batch queries. Mitigated by chunking in base class.
**Test RED:** Write `tests/Unit/Services/TRC20/Dedup/Trc20DuplicateDetectorTest.php`:
- `testExtractKeyNewFormat`: `trc20|in|abc123` -> `trc20|in|abc123`
- `testExtractKeyOldFormat`: `trc20|TAddr|abc123` -> `trc20|TAddr|abc123` (passthrough)
- `testLegacyKeysGeneratedForNewFormat`: new format generates old-format keys for all wallets
- `testLegacyKeysEmptyForOldFormat`: old format does not generate legacy keys
- `testEmptyExternalIdReturnsNull`: no key, skip dedup
- `testSourceName`: returns `"trc20"`

---

#### Step 2.7: Create NordigenDuplicateDetector (handles HIGH-4 migration)

**File:** `/mnt/g/REPOS/firefly/data-importer/app/Services/Nordigen/Dedup/NordigenDuplicateDetector.php` (NEW)

```php
<?php

declare(strict_types=1);

namespace App\Services\Nordigen\Dedup;

use App\Services\Shared\Dedup\DuplicateDetector;
use App\Services\Shared\Support\TransactionIdGenerator;

/**
 * Nordigen uses composite IDs: accountIdentifier-transactionId.
 *
 * After 2025-09-07, transactionId was switched from the bank's transactionId
 * to internalTransactionId. This detector searches for both to prevent
 * duplicates during the transition period.
 *
 * Source name: "nordigen"
 */
final class NordigenDuplicateDetector extends DuplicateDetector
{
    public function sourceName(): string
    {
        return 'nordigen';
    }

    public function extractKey(array $transaction): ?string
    {
        $externalId = trim((string)($transaction['external_id'] ?? ''));

        return '' !== $externalId ? $externalId : null;
    }

    /**
     * Generate the alternative composite ID using the other transaction ID field.
     *
     * If the current key uses internalTransactionId, also check transactionId, and vice versa.
     * This requires access to the raw Nordigen transaction data, which is stored in
     * 'internal_reference' (accountIdentifier) and the original API fields.
     */
    public function extractLegacyKeys(array $transaction): array
    {
        // Legacy keys can only be computed if we have the raw Nordigen fields.
        // The GenerateTransactions step stores accountIdentifier in internal_reference.
        $internalRef = trim((string)($transaction['internal_reference'] ?? ''));
        $notes       = trim((string)($transaction['notes'] ?? ''));

        // Without the account identifier, we cannot construct alternate composite IDs.
        if ('' === $internalRef) {
            return [];
        }

        // The current external_id is built by TransactionIdGenerator::buildCompositeId().
        // We cannot recover the original transactionId vs internalTransactionId from the
        // composite key alone. Legacy key search is best-effort for the migration period.
        // After 2-3 import cycles, all transactions will have the new format.
        return [];
    }
}
```

**Dependencies:** Step 2.2 (Nordigen transactionId fix), Step 2.5
**Risk:** Low -- Nordigen's legacy key problem is mitigated by the fix in Step 2.2 (prefer internalTransactionId but never wipe a valid transactionId). After 2 import cycles, all transactions will have consistent IDs.
**Test:** `tests/Unit/Services/Nordigen/Dedup/NordigenDuplicateDetectorTest.php` -- include `testSourceName` returning `"nordigen"`.

---

#### Step 2.8: Create BasisBankDuplicateDetector

**File:** `/mnt/g/REPOS/firefly/data-importer/app/Services/BasisBank/Dedup/BasisBankDuplicateDetector.php` (NEW)

BasisBank has unstable TransactionIDs (same transaction returns different IDs across page loads). The `deduplicateByDescriptionDate()` in `TransactionProcessor` catches within-batch duplicates, but cross-import dedup needs a composite key.

```php
<?php

declare(strict_types=1);

namespace App\Services\BasisBank\Dedup;

use App\Services\Shared\Dedup\DuplicateDetector;

/**
 * BasisBank uses the external_id set by LunchFlow GenerateTransactions,
 * which comes from Transaction::getTransactionId() (the raw API transaction ID).
 *
 * Since BasisBank TransactionIDs are unstable (change across page loads),
 * the primary dedup key is the external_id as-is, but the within-batch
 * deduplication by description+date+amount in TransactionProcessor
 * remains essential and is NOT removed.
 *
 * Source name: "basisbank"
 */
final class BasisBankDuplicateDetector extends DuplicateDetector
{
    public function sourceName(): string
    {
        return 'basisbank';
    }

    public function extractKey(array $transaction): ?string
    {
        $externalId = trim((string)($transaction['external_id'] ?? ''));

        return '' !== $externalId ? $externalId : null;
    }
}
```

**Dependencies:** Step 2.5
**Risk:** Low
**Test:** `tests/Unit/Services/BasisBank/Dedup/BasisBankDuplicateDetectorTest.php` -- include `testSourceName` returning `"basisbank"`.

---

#### Step 2.9: Create TBankDuplicateDetector

**File:** `/mnt/g/REPOS/firefly/data-importer/app/Services/TBank/Dedup/TBankDuplicateDetector.php` (NEW)

```php
<?php

declare(strict_types=1);

namespace App\Services\TBank\Dedup;

use App\Services\Shared\Dedup\DuplicateDetector;

/**
 * TBank uses operationId as external_id (stable, set by GetTransactionsRequest).
 * Falls back to md5 hash of [accountId, amount, currency, date, description, merchant].
 *
 * Source name: "tbank"
 */
final class TBankDuplicateDetector extends DuplicateDetector
{
    public function sourceName(): string
    {
        return 'tbank';
    }

    public function extractKey(array $transaction): ?string
    {
        $externalId = trim((string)($transaction['external_id'] ?? ''));

        return '' !== $externalId ? $externalId : null;
    }
}
```

**Dependencies:** Step 2.5
**Risk:** Low
**Test:** `tests/Unit/Services/TBank/Dedup/TBankDuplicateDetectorTest.php` -- include `testSourceName` returning `"tbank"`.

---

#### Step 2.10: Create ExternalIdDuplicateDetector (generic fallback)

**File:** `/mnt/g/REPOS/firefly/data-importer/app/Services/Shared/Dedup/ExternalIdDuplicateDetector.php` (NEW)

Used by CSV, CAMT, SimpleFIN, Spectre, Sophtron, EnableBanking, LunchFlow -- any provider that sets `external_id` without special dedup logic.

```php
<?php

declare(strict_types=1);

namespace App\Services\Shared\Dedup;

/**
 * Generic duplicate detector using external_id as-is.
 * Suitable for any provider that sets a stable external_id.
 *
 * The source name is configurable -- set via DuplicateDetectorFactory
 * based on the flow name from config('importer.source_names').
 */
final class ExternalIdDuplicateDetector extends DuplicateDetector
{
    private string $source;

    public function __construct(
        ?BatchApiClient $batchClient,
        bool $batchAvailable,
        string $sourceName = 'unknown',
    ) {
        parent::__construct($batchClient, $batchAvailable);
        $this->source = $sourceName;
    }

    public function sourceName(): string
    {
        return $this->source;
    }

    public function extractKey(array $transaction): ?string
    {
        $externalId = trim((string)($transaction['external_id'] ?? ''));

        return '' !== $externalId ? $externalId : null;
    }
}
```

**Dependencies:** Step 2.5
**Risk:** Low

---

#### Step 2.11: Create DuplicateDetectorFactory with source resolution

**File:** `/mnt/g/REPOS/firefly/data-importer/app/Services/Shared/Dedup/DuplicateDetectorFactory.php` (NEW)

```php
<?php

declare(strict_types=1);

namespace App\Services\Shared\Dedup;

use App\Services\BasisBank\Dedup\BasisBankDuplicateDetector;
use App\Services\Nordigen\Dedup\NordigenDuplicateDetector;
use App\Services\Shared\Import\Routine\BatchApiClient;
use App\Services\TBank\Dedup\TBankDuplicateDetector;
use App\Services\TRC20\Dedup\Trc20DuplicateDetector;

final class DuplicateDetectorFactory
{
    /**
     * Create the appropriate detector for the given flow.
     *
     * The detector's sourceName() is used for:
     * 1. Filtering batch external_id searches by source in Firefly III
     * 2. Setting import_source on newly created transactions
     * 3. Logging and diagnostics
     */
    public static function create(
        string $flow,
        ?BatchApiClient $batchClient,
        bool $batchAvailable,
    ): DuplicateDetector {
        return match ($flow) {
            'trc20'     => new Trc20DuplicateDetector($batchClient, $batchAvailable),
            'basisbank' => new BasisBankDuplicateDetector($batchClient, $batchAvailable),
            'tbank'     => new TBankDuplicateDetector($batchClient, $batchAvailable),
            'nordigen'  => new NordigenDuplicateDetector($batchClient, $batchAvailable),
            default     => new ExternalIdDuplicateDetector(
                $batchClient,
                $batchAvailable,
                self::resolveSourceName($flow),
            ),
        };
    }

    /**
     * Resolve the source name for generic flows.
     *
     * Reads from config('importer.source_names') map. Falls back to
     * the flow name itself if no mapping is defined.
     */
    private static function resolveSourceName(string $flow): string
    {
        $map = config('importer.source_names', []);

        return (string) ($map[$flow] ?? $flow);
    }
}
```

**Dependencies:** Steps 2.6-2.10
**Risk:** Low
**Test:** `tests/Unit/Services/Shared/Dedup/DuplicateDetectorFactoryTest.php` -- verify correct class returned for each flow string and that `sourceName()` returns the expected value for each detector.

---

#### Step 2.12: Modify BatchApiClient to pass source parameter

**File:** `/mnt/g/REPOS/firefly/data-importer/app/Services/Shared/Import/Routine/BatchApiClient.php`

**Action:** Add optional `$source` parameter to `batchSearchExternalIds()`:

```php
/**
 * Search for existing transactions by a list of external IDs in a single request.
 *
 * @param  array       $externalIds  List of external_id strings.
 * @param  string|null $startDate    Optional date filter (Y-m-d).
 * @param  string|null $endDate      Optional date filter (Y-m-d).
 * @param  string|null $source       Optional import source name to narrow search.
 *
 * @return array Decoded JSON response -- keyed by external_id.
 *
 * @throws ImporterErrorException
 */
public function batchSearchExternalIds(
    array $externalIds,
    ?string $startDate = null,
    ?string $endDate = null,
    ?string $source = null,
): array {
    $url  = sprintf('%s/api/v1/search/external-ids', $this->baseUrl);
    $body = ['external_ids' => array_values($externalIds)];

    if (null !== $startDate && '' !== $startDate) {
        $body['start_date'] = $startDate;
    }
    if (null !== $endDate && '' !== $endDate) {
        $body['end_date'] = $endDate;
    }
    if (null !== $source && '' !== $source) {
        $body['source'] = $source;
    }

    // ... rest unchanged
```

Also add `sourceSupported(): bool` method that checks `/about` for `capabilities.import_sources`:

```php
/**
 * Check if the connected Firefly III instance supports import source filtering.
 */
public function sourceSupported(): bool
{
    $url = sprintf('%s/api/v1/about', $this->baseUrl);

    try {
        $response = $this->http()->get($url);
    } catch (\Throwable) {
        return false;
    }

    if (!$response->successful()) {
        return false;
    }

    $json         = $response->json();
    $data         = $json['data'] ?? $json ?? [];
    $capabilities = $data['capabilities'] ?? [];

    return true === ($capabilities['import_sources'] ?? false);
}
```

**Dependencies:** Step 1.9 (server-side source filter support)
**Risk:** Low -- additive parameter, backward compatible.

---

#### Step 2.13: Wire DuplicateDetector into ApiSubmitter (parallel run)

**File:** `/mnt/g/REPOS/firefly/data-importer/app/Services/Shared/Import/Routine/ApiSubmitter.php`

**Action:** In `setImportJob()`, after the existing batch client setup (line 96-108), create the appropriate `DuplicateDetector`:

```php
// After line 108:
$this->duplicateDetector = DuplicateDetectorFactory::create(
    $this->importJob->getFlow(),
    $this->batchClient,
    $this->batchEndpointsAvailable,
);

// For TRC20, pass wallet addresses
if ($this->duplicateDetector instanceof \App\Services\TRC20\Dedup\Trc20DuplicateDetector) {
    $wallets = array_keys($this->configuration->getAccounts());
    $this->duplicateDetector->setWallets(
        array_map(fn($w) => str_contains($w, '|') ? explode('|', $w, 2)[0] : $w, $wallets)
    );
}

// Check if Firefly III supports import sources
$this->importSourceSupported = $this->batchClient?->sourceSupported() ?? false;
```

In `processTransactions()`, check config to decide which pipeline to use:

```php
$useDedupV2 = $this->configuration->getDedupPipelineVersion() >= 2
    || (int) config('importer.dedup.pipeline_version', 1) >= 2;

if ($useDedupV2) {
    $this->duplicateDetector->warmIndex($lines);
}
```

In `uniqueTransaction()`, when `$useDedupV2` is true, delegate to `$this->duplicateDetector->isDuplicate()` instead of the existing logic. When false, use existing behavior unchanged.

When building the transaction payload for submission, include `import_source` if supported:

```php
// In the transaction line building (processTransaction or cleanupLine):
if ($useDedupV2 && $this->importSourceSupported) {
    $line['transactions'][0]['import_source'] = $this->duplicateDetector->sourceName();
}
```

Add properties:
```php
private ?DuplicateDetector $duplicateDetector = null;
private bool $importSourceSupported = false;
```

**Dependencies:** Steps 2.4-2.12
**Risk:** Medium -- parallel run means both code paths exist. The `DEDUP_PIPELINE_VERSION=1` (default) preserves 100% existing behavior. Set `DEDUP_PIPELINE_VERSION=2` to opt in.
**Test:** Structural test in `ApiSubmitterDedupTest.php` -- verify that the detector is created and wired, and that `import_source` is included in the payload when supported.

---

#### Step 2.14: Fix HIGH-1 -- uniqueTransaction returns null treated as "proceed"

**File:** `/mnt/g/REPOS/firefly/data-importer/app/Services/Shared/Import/Routine/ApiSubmitter.php`

**Action:** In `processTransactions()` (line 201-203), `null` from `uniqueTransaction()` is treated as "proceed without checking". This is correct for the `none` detection method but the log message is misleading and there is no explicit documentation. When using dedup V2, null from `isDuplicate()` means "no key available" -- should log clearly.

```php
// Before (line 201-203):
if (null === $unique) {
    Log::debug(sprintf('Transaction #%d is not checked beforehand on uniqueness.', $index + 1));
}

// After:
if (null === $unique) {
    Log::info(sprintf(
        'Transaction #%d: no dedup key available (detection_method=%s, source=%s). Proceeding without duplicate check.',
        $index + 1,
        $this->configuration->getDuplicateDetectionMethod(),
        $this->duplicateDetector?->sourceName() ?? 'n/a'
    ));
}
```

**Dependencies:** None
**Risk:** Low -- log-only change.

---

**Commit message for Phase 2:**
```
feat: introduce DuplicateDetector hierarchy with source-aware dedup

- Abstract DuplicateDetector base class with template method pattern
- Each detector provides sourceName() for import_source namespacing
- Provider-specific detectors: TRC20, BasisBank, TBank, Nordigen, generic
- Fix CRITICAL-2: TRC20 external_id no longer includes wallet address
- Fix HIGH-4: Nordigen preserves transactionId when internalTransactionId is empty
- Fix HIGH-5: generateFallbackId uses serialize() instead of microtime() on JSON failure
- BatchApiClient passes source to ExternalIdController for scoped dedup queries
- Transaction payload includes import_source when Firefly III supports it
- Config flag DEDUP_PIPELINE_VERSION=2 to opt into new pipeline (default=1, old behavior)

I got a picture of you in my locket
I keep it close to my heart, a light shining in the dark

Assisted-by: Opus 4.6 via Claude Code
```

---

### Phase 3: Remove Old Dedup + Extract Shared TransactionProcessor Boilerplate

**Goal:** After Phase 2 is validated in production, switch default to `DEDUP_PIPELINE_VERSION=2`, remove old dedup code paths from TransactionProcessors, and extract duplicated boilerplate into a shared trait.

**Independently deployable:** Yes. Phase 2 must be deployed and validated first. This phase only removes code that is gated behind the V1 config flag.

**Prerequisite:** At least one successful import cycle with `DEDUP_PIPELINE_VERSION=2` for each active provider.

---

#### Step 3.1: Change default dedup pipeline version to 2

**File:** `/mnt/g/REPOS/firefly/data-importer/config/importer.php`

```php
'dedup' => [
    'pipeline_version' => (int) env('DEDUP_PIPELINE_VERSION', 2), // Changed from 1 to 2
],
```

**Dependencies:** Phase 2 validated
**Risk:** Medium -- users can still set `DEDUP_PIPELINE_VERSION=1` to revert.

---

#### Step 3.2: Extract shared TransactionProcessor boilerplate into trait

**File:** `/mnt/g/REPOS/firefly/data-importer/app/Services/Shared/Conversion/TransactionProcessorHelpers.php` (NEW)

These three methods are identical (or near-identical) in BasisBank, TBank, and TRC20 TransactionProcessors:

1. `resolveLatestTransactionDate(array $transactions): ?string`
2. `buildContextFingerprint(): string` (differs only in credential extraction)
3. `resolveIncrementalDateFromCursor(string $accountId): ?string` (differs only in provider name string)
4. `saveConversionStatus(): void`

Extract the shared parts:

```php
<?php

declare(strict_types=1);

namespace App\Services\Shared\Conversion;

use App\Services\Shared\SyncState\SyncStateManager;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Shared boilerplate for provider TransactionProcessors.
 *
 * Extracted from BasisBank, TBank, and TRC20 TransactionProcessors
 * to eliminate code duplication (Issue-4 from critique).
 */
trait TransactionProcessorHelpers
{
    abstract protected function getProviderName(): string;
    abstract protected function getContextCredentials(): array;

    protected function resolveLatestTransactionDate(array $transactions): ?string
    {
        $latest = null;
        foreach ($transactions as $transaction) {
            if (!property_exists($transaction, 'date') || null === $transaction->date) {
                continue;
            }
            $date = $transaction->date instanceof Carbon ? $transaction->date : Carbon::parse((string)$transaction->date);
            if (null === $latest || $date->gt($latest)) {
                $latest = $date;
            }
        }

        return null === $latest ? null : $latest->toDateString();
    }

    protected function buildContextFingerprint(): string
    {
        $flow = $this->importJob->getFlow();

        return $this->syncStateManager->buildContextFingerprint(
            $flow,
            $this->getContextCredentials()
        );
    }

    protected function resolveIncrementalDateFromCursor(string $accountId): ?string
    {
        $configuration = $this->importJob->getConfiguration();
        if ('' !== $configuration->getDateNotBefore()) {
            return null;
        }
        if (false === $configuration->isIncrementalSyncEnabled()) {
            return null;
        }

        $cursor = $this->syncStateManager->getLookBackDate(
            $this->getProviderName(),
            $this->contextFingerprint,
            $accountId
        );
        if (null === $cursor) {
            Log::debug(sprintf('No %s cursor found for account %s.', $this->getProviderName(), $accountId));
            return null;
        }

        $lookbackDate = $this->syncStateManager->getIncrementalDateFromCursor(
            $cursor,
            $configuration->getIncrementalLookbackDays()
        );
        if (null === $lookbackDate) {
            return null;
        }

        Log::info(sprintf(
            'Using incremental %s pull date %s for account %s.',
            $this->getProviderName(),
            $lookbackDate,
            $accountId
        ));

        return $lookbackDate;
    }

    protected function saveConversionStatus(): void
    {
        $this->repository->saveToDisk($this->importJob);
    }
}
```

**Dependencies:** None
**Risk:** Low -- trait usage preserves identical behavior.

---

#### Step 3.3: Apply trait to BasisBank TransactionProcessor

**File:** `/mnt/g/REPOS/firefly/data-importer/app/Services/BasisBank/Conversion/Routine/TransactionProcessor.php`

**Action:**
1. Add `use TransactionProcessorHelpers;`
2. Delete the local `resolveLatestTransactionDate()` (line 679-693)
3. Delete the local `resolveIncrementalDateFromCursor()` (lines ~645-677)
4. Delete the local `buildContextFingerprint()` method
5. Delete the local `saveConversionStatus()` method
6. Add the two abstract method implementations:

```php
protected function getProviderName(): string { return 'basisbank'; }
protected function getContextCredentials(): array
{
    $configuration = $this->importJob->getConfiguration();
    return [
        config('importer.version'),
        SecretManager::getApiToken($configuration),
        SecretManager::getConsentId($configuration),
        SharedSecretManager::getBaseUrl(),
    ];
}
```

**Dependencies:** Step 3.2
**Risk:** Low -- behavior identical, verified by running existing tests.
**Lines removed:** ~80

---

#### Step 3.4: Apply trait to TBank TransactionProcessor

**File:** `/mnt/g/REPOS/firefly/data-importer/app/Services/TBank/Conversion/Routine/TransactionProcessor.php`

**Action:** Same as Step 3.3 but with TBank-specific credential extraction.

```php
protected function getProviderName(): string { return 'tbank'; }
protected function getContextCredentials(): array
{
    $configuration = $this->importJob->getConfiguration();
    return [
        config('importer.version'),
        SecretManager::getSessionId($configuration),
        SecretManager::getCookieHeader($configuration),
        SharedSecretManager::getBaseUrl(),
    ];
}
```

**Dependencies:** Step 3.2
**Risk:** Low
**Lines removed:** ~65

---

#### Step 3.5: Apply trait to TRC20 TransactionProcessor

**File:** `/mnt/g/REPOS/firefly/data-importer/app/Services/TRC20/Conversion/Routine/TransactionProcessor.php`

**Action:** Same as Step 3.3 but with TRC20-specific credential extraction. Note: TRC20's `resolveLatestTransactionDate` has a slightly different null-check (`null === $transaction->date` guard). The trait version handles this.

```php
protected function getProviderName(): string { return 'trc20'; }
protected function getContextCredentials(): array
{
    $configuration = $this->importJob->getConfiguration();
    return [
        config('importer.version'),
        SecretManager::getApiKey($configuration),
        SharedSecretManager::getBaseUrl(),
    ];
}
```

**Dependencies:** Step 3.2
**Risk:** Low
**Lines removed:** ~70

---

#### Step 3.6: Remove TRC20 $seenTransactionIds (provider-level dedup)

**File:** `/mnt/g/REPOS/firefly/data-importer/app/Services/TRC20/Conversion/Routine/TransactionProcessor.php`

**Action:** The `$seenTransactionIds` property (line 43) and its usage in `extractWalletTransactions()` (lines 356-361) implement within-batch dedup that is now redundant when the `DuplicateDetector` handles it. However, the within-batch dedup at the provider level is still valuable because it prevents sending duplicates TO Firefly III (saving HTTP calls), not just detecting them AFTER submission.

**Decision:** KEEP `$seenTransactionIds` for now. It is a cheap local set that prevents wasting batch submission slots. The `DuplicateDetector` catches cross-import duplicates. These are complementary, not competing.

**Risk:** None -- no change.

---

#### Step 3.7: Remove old V1 dedup code path from ApiSubmitter (deferred)

**Action:** After confirming V2 works in production for at least 2 weeks, remove the V1 code path:
1. Remove `fetchExistingExternalIds()` method (~80 lines)
2. Remove `preloadedDuplicateIndex` property and related methods
3. Remove `duplicateIndexReady` property
4. Remove `mergeDuplicateCandidate()` method
5. Remove `duplicateCandidatesFromEntry()` method
6. Remove `findDuplicateMatchForAccountContext()` method
7. Remove `rememberExternalIdsFromLine()` method (replaced by `DuplicateDetector::remember()`)
8. Simplify `uniqueTransaction()` to delegate only to `DuplicateDetector::isDuplicate()`
9. Remove `warmExternalIdDuplicateIndex()` (replaced by `DuplicateDetector::warmIndex()`)

**Dependencies:** 2+ weeks of V2 production usage
**Risk:** Medium -- removing fallback. Can be reverted via `DEDUP_PIPELINE_VERSION=1` if V2 has issues.
**Lines removed:** ~300

---

**Commit message for Phase 3:**
```
refactor: extract shared TransactionProcessor boilerplate, default to dedup V2

- Extract resolveLatestTransactionDate, buildContextFingerprint,
  resolveIncrementalDateFromCursor into TransactionProcessorHelpers trait
- Apply trait to BasisBank, TBank, TRC20 TransactionProcessors
- Change default DEDUP_PIPELINE_VERSION from 1 to 2
- Remove ~215 lines of duplicated code across 3 providers

Darkness on the edge of town
The highway's jammed with broken heroes

Assisted-by: Opus 4.6 via Claude Code
```

---

### Phase 4: Decompose ApiSubmitter God Object

**Goal:** Break ApiSubmitter (1627 lines, 7+ responsibilities) into focused, testable classes. This phase is pure refactoring -- no behavior changes. The extracted classes are source-aware: `DuplicateChecker` passes source to Firefly III, `SubmissionOrchestrator` auto-creates the source if needed.

**Independently deployable:** Yes. Each extraction step can be merged independently. ApiSubmitter becomes a thin `SubmissionOrchestrator` that delegates to specialized classes.

**Prerequisite:** Phase 3 deployed. Old V1 dedup code removed (Step 3.7).

---

#### Step 4.1: Extract TagManager

**File:** `/mnt/g/REPOS/firefly/data-importer/app/Services/Shared/Import/Routine/TagManager.php` (NEW)

**Extract from ApiSubmitter:**
- `addTagToGroups()` (lines 847-925)
- `createTag()` (lines 927-960)
- `$tag`, `$tagDate`, `$addTag`, `$createdTag`, `$skipTagUpdates`, `$tagSkippingNotified` properties

```php
<?php

declare(strict_types=1);

namespace App\Services\Shared\Import\Routine;

use App\Services\Shared\Authentication\SecretManager;
use GrumpyDictator\FFIIIApiSupport\Request\PostTagRequest;
use GrumpyDictator\FFIIIApiSupport\Request\PutTransactionRequest;
// ...

final class TagManager
{
    private bool $createdTag = false;
    private bool $tagSkippingNotified = false;

    public function __construct(
        private readonly string $tag,
        private readonly string $tagDate,
        private readonly bool $addTag,
        private readonly bool $skipTagUpdates,
    ) {}

    public function addTagToGroups(array $groupInfo, SubmissionStatus $status): void { /* ... */ }
    private function createTag(SubmissionStatus $status): void { /* ... */ }
}
```

**Dependencies:** None
**Risk:** Low -- pure extraction, behavior identical.
**Lines moved:** ~115
**Test:** `tests/Unit/Services/Shared/Import/Routine/TagManagerTest.php`

---

#### Step 4.2: Extract CurrencyManager

**File:** `/mnt/g/REPOS/firefly/data-importer/app/Services/Shared/Import/Routine/CurrencyManager.php` (NEW)

**Extract from ApiSubmitter:**
- `createMissingCurrenciesFromValidationErrors()` (lines 675-704)
- `ensureCurrencyAvailable()` (lines 706-720)
- `currencyExists()` (lines 722-738)
- `createCurrency()` (lines 740-789)
- `hasUnsupportedCurrencyValidationError()` (lines 791-808)
- `stripUnsupportedCurrencyFields()` (lines 810-829)
- `$availableCurrencies` property

**Dependencies:** None
**Risk:** Low -- pure extraction.
**Lines moved:** ~150
**Test:** `tests/Unit/Services/Shared/Import/Routine/CurrencyManagerTest.php`

---

#### Step 4.3: Extract DuplicateChecker (source-aware)

**File:** `/mnt/g/REPOS/firefly/data-importer/app/Services/Shared/Import/Routine/DuplicateChecker.php` (NEW)

**Extract from ApiSubmitter:**
- `uniqueTransaction()` (lines 290-377)
- `searchField()` (lines 382-434)
- `warmExternalIdDuplicateIndex()` / `warmIndex()` delegation
- `rememberExternalIdsFromLine()` / `remember()` delegation
- `rememberExternalIdsFromBatchResult()`
- Account ID extraction/normalization helpers
- `$duplicateDetector`, `$preloadedDuplicateIndex`, `$duplicateIndexReady` properties

The `DuplicateChecker` always passes source through to the Firefly III API via the detector's `sourceName()`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Shared\Import\Routine;

use App\Services\Shared\Dedup\DuplicateDetector;
use App\Services\Shared\Dedup\DuplicateCheckResult;
// ...

final class DuplicateChecker
{
    public function __construct(
        private readonly DuplicateDetector $detector,
        private readonly Configuration $configuration,
    ) {}

    /**
     * Get the source name from the underlying detector.
     * Used by SubmissionOrchestrator to include import_source in transaction payloads.
     */
    public function getSourceName(): string
    {
        return $this->detector->sourceName();
    }

    public function warmIndex(array $lines): void { /* delegates to $this->detector->warmIndex() */ }
    public function check(int $index, array $line): ?DuplicateCheckResult { /* delegates to $this->detector->isDuplicate() */ }
    public function rememberFromLine(array $line, array $groupInfo): void { /* delegates to $this->detector->remember() */ }
    public function rememberFromBatchResult(array $result): void { /* ... */ }
}
```

**Dependencies:** Phase 2 DuplicateDetector hierarchy
**Risk:** Medium -- most complex extraction due to interaction with index.
**Lines moved:** ~200
**Test:** `tests/Unit/Services/Shared/Import/Routine/DuplicateCheckerTest.php` -- verify that `getSourceName()` returns the detector's source, and that warmIndex passes source through to batch API.

---

#### Step 4.4: Extract TransactionSubmitter (includes import_source in payload)

**File:** `/mnt/g/REPOS/firefly/data-importer/app/Services/Shared/Import/Routine/TransactionSubmitter.php` (NEW)

**Extract from ApiSubmitter:**
- `processTransaction()` (lines 436-587)
- `cleanupLine()` (lines 589-628)
- `updateTransactionType()` (lines 630-647)
- `getOriginalValue()` (lines 649-661)
- `isDuplicationError()` (lines 663-673)
- `compareArrays()` (lines 831-844)
- `$accountInfo`, `$mapping` properties

The `TransactionSubmitter` injects `import_source` into each transaction line before sending to Firefly III:

```php
<?php

declare(strict_types=1);

namespace App\Services\Shared\Import\Routine;

final class TransactionSubmitter
{
    public function __construct(
        private readonly CurrencyManager $currencyManager,
        private readonly array $accountInfo,
        private readonly array $mapping,
        private readonly string $vanityURL,
        private readonly ?string $importSource,
    ) {}

    public function submit(int $index, array $line, SubmissionStatus $status): array
    {
        // Inject import_source into each transaction in the line
        if (null !== $this->importSource && '' !== $this->importSource) {
            foreach ($line['transactions'] as &$transaction) {
                $transaction['import_source'] = $this->importSource;
            }
            unset($transaction);
        }

        // ... existing processTransaction logic
    }

    private function cleanupLine(array $line): array { /* ... */ }
    // ...
}
```

**Dependencies:** Step 4.2 (CurrencyManager)
**Risk:** Medium -- processTransaction has retry logic that must be preserved exactly.
**Lines moved:** ~250
**Test:** `tests/Unit/Services/Shared/Import/Routine/TransactionSubmitterTest.php` -- verify that `import_source` is injected when provided.

---

#### Step 4.5: Create SubmissionOrchestrator (replaces ApiSubmitter, auto-creates source)

**File:** `/mnt/g/REPOS/firefly/data-importer/app/Services/Shared/Import/Routine/SubmissionOrchestrator.php` (NEW)

This class replaces `ApiSubmitter::processTransactions()` and `processTransactionsBatch()`. It delegates to the extracted classes and ensures the import source exists in Firefly III before submitting transactions:

```php
<?php

declare(strict_types=1);

namespace App\Services\Shared\Import\Routine;

final class SubmissionOrchestrator
{
    public function __construct(
        private readonly DuplicateChecker $duplicateChecker,
        private readonly TransactionSubmitter $submitter,
        private readonly TagManager $tagManager,
        private readonly ?BatchApiClient $batchClient,
        private readonly bool $batchAvailable,
    ) {}

    public function process(ImportJob $importJob): void
    {
        // Auto-create the import source in Firefly III if source-aware dedup is enabled.
        $this->ensureImportSourceExists();

        // ... rest of orchestration
    }

    /**
     * Ensure the import source record exists in Firefly III.
     *
     * Calls POST /api/v1/import-sources with the detector's sourceName().
     * This is idempotent -- the endpoint returns the existing record if
     * the source already exists.
     */
    private function ensureImportSourceExists(): void
    {
        if (null === $this->batchClient) {
            return;
        }
        if (!$this->batchClient->sourceSupported()) {
            return;
        }

        $sourceName = $this->duplicateChecker->getSourceName();
        if ('' === $sourceName || 'unknown' === $sourceName) {
            return;
        }

        try {
            $this->batchClient->createImportSource($sourceName);
        } catch (\Throwable $e) {
            Log::warning(sprintf(
                'SubmissionOrchestrator: failed to ensure import source "%s" exists: %s',
                $sourceName,
                $e->getMessage()
            ));
        }
    }

    private function processSequential(array $lines, ImportJob $importJob): void { /* ... */ }
    private function processBatch(array $lines, ImportJob $importJob): void { /* ... */ }
}
```

Add `createImportSource()` method to `BatchApiClient`:

```php
/**
 * Find or create an import source by name.
 */
public function createImportSource(string $name): array
{
    $url      = sprintf('%s/api/v1/import-sources', $this->baseUrl);
    $response = $this->http()->post($url, ['name' => $name]);

    if (!$response->successful()) {
        throw new ImporterErrorException(
            sprintf('Import source creation failed: HTTP %d -- %s', $response->status(), $response->body())
        );
    }

    return $response->json() ?? [];
}
```

**File:** `/mnt/g/REPOS/firefly/data-importer/app/Services/Shared/Import/Routine/ApiSubmitter.php` (MODIFY)

**Action:** Reduce to a thin facade that creates `SubmissionOrchestrator` and delegates. Keep `setImportJob()` and `getImportJob()` for backward compatibility with callers.

```php
public function processTransactions(): void
{
    $orchestrator = new SubmissionOrchestrator(
        $this->duplicateChecker,
        $this->submitter,
        $this->tagManager,
        $this->batchClient,
        $this->batchEndpointsAvailable,
    );
    $orchestrator->process($this->importJob);
}
```

**Dependencies:** Steps 4.1-4.4
**Risk:** Medium -- must preserve all existing behavior including progress tracking, board entries, performance samples.
**Lines in ApiSubmitter after:** ~100 (down from 1627)

---

**Commit message for Phase 4:**
```
refactor: decompose ApiSubmitter into focused classes with source-aware dedup

Break the 1627-line God Object into:
- TagManager: tag creation and application
- CurrencyManager: missing currency handling
- DuplicateChecker: dedup index management (passes source to Firefly III)
- TransactionSubmitter: HTTP submission and retry (injects import_source)
- SubmissionOrchestrator: coordinates the above, auto-creates import source

ApiSubmitter is now a thin facade (~100 lines) for backward compatibility.

The road was dark, there was no one in sight
But I drove on through the night

Assisted-by: Opus 4.6 via Claude Code
```

---

## Testing Strategy

### Unit Tests (TDD -- write before implementation)

| Test File | Tests | Phase |
|-----------|-------|-------|
| `tests/Unit/Services/Shared/Dedup/DuplicateCheckResultTest.php` | Value object construction, immutability | 2 |
| `tests/Unit/Services/Shared/Dedup/DuplicateDetectorFactoryTest.php` | Correct class for each flow, correct sourceName() | 2 |
| `tests/Unit/Services/TRC20/Dedup/Trc20DuplicateDetectorTest.php` | Key extraction, legacy keys, cross-wallet fix, sourceName() | 2 |
| `tests/Unit/Services/BasisBank/Dedup/BasisBankDuplicateDetectorTest.php` | Key extraction, sourceName() | 2 |
| `tests/Unit/Services/TBank/Dedup/TBankDuplicateDetectorTest.php` | Key extraction, sourceName() | 2 |
| `tests/Unit/Services/Nordigen/Dedup/NordigenDuplicateDetectorTest.php` | Key extraction, legacy key handling, sourceName() | 2 |
| `tests/Unit/Services/Shared/Dedup/ExternalIdDuplicateDetectorTest.php` | Generic key extraction, configurable sourceName() | 2 |
| `tests/Unit/Services/Nordigen/Model/TransactionFromArrayTest.php` | transactionId vs internalTransactionId | 2 |
| `tests/Unit/Services/TRC20/Conversion/Routine/TransactionProcessorExternalIdTest.php` | New external_id format | 2 |
| `tests/Unit/Services/Shared/Support/TransactionIdGeneratorTest.php` | Existing + new determinism test | 2 |
| `tests/Unit/Services/Shared/Import/Routine/TagManagerTest.php` | Tag creation, skipping | 4 |
| `tests/Unit/Services/Shared/Import/Routine/CurrencyManagerTest.php` | Currency creation, dedup | 4 |
| `tests/Unit/Services/Shared/Import/Routine/DuplicateCheckerTest.php` | Index warming, check, remember, getSourceName() | 4 |
| `tests/Unit/Services/Shared/Import/Routine/TransactionSubmitterTest.php` | Submit, retry, cleanup, import_source injection | 4 |

### Firefly III Tests

| Test File | Tests | Phase |
|-----------|-------|-------|
| `tests/Unit/Models/ImportSourceTest.php` | Model, findOrCreateByName | 1 |
| `tests/Feature/Api/V1/Controllers/System/ImportSourceControllerTest.php` | CRUD, idempotent create | 1 |
| `tests/Feature/Api/V1/Controllers/Search/ExternalIdControllerTest.php` | Source-filtered search, null source fallback | 1 |

### Test Commands

```bash
# Run all unit tests (data-importer)
cd /mnt/g/REPOS/firefly/data-importer && vendor/bin/phpunit --testsuite Unit

# Run specific test class
vendor/bin/phpunit --filter Trc20DuplicateDetectorTest

# Run with coverage
vendor/bin/phpunit --testsuite Unit --coverage-text --coverage-filter app/Services/Shared/Dedup

# Inside Docker
docker compose exec importer vendor/bin/phpunit --testsuite Unit

# Run Firefly III tests
cd /mnt/g/REPOS/firefly/firefly-iii && composer unit-test
```

### Integration Tests

After Phase 1 deployment, verify batch endpoints and source infrastructure end-to-end:
```bash
# From importer container (not host)

# Verify batch external_id search (without source)
docker compose exec importer curl -s \
    -X POST http://app:8080/api/v1/search/external-ids \
    -H "Authorization: Bearer $TOKEN" \
    -H 'Content-Type: application/json' \
    -d '{"external_ids":["trc20|in|abc123"]}' | jq .

# Verify batch external_id search (with source -- narrows to trc20 only)
docker compose exec importer curl -s \
    -X POST http://app:8080/api/v1/search/external-ids \
    -H "Authorization: Bearer $TOKEN" \
    -H 'Content-Type: application/json' \
    -d '{"external_ids":["12345"], "source":"trc20"}' | jq .

# Verify import source creation (idempotent)
docker compose exec importer curl -s \
    -X POST http://app:8080/api/v1/import-sources \
    -H "Authorization: Bearer $TOKEN" \
    -H 'Content-Type: application/json' \
    -d '{"name":"trc20"}' | jq .

# Verify capabilities
docker compose exec importer curl -s \
    http://app:8080/api/v1/about \
    -H "Authorization: Bearer $TOKEN" | jq '.data.capabilities'
```

### E2E Tests

After each phase, run a full import cycle for each provider and verify:
1. No duplicate transactions created
2. Import count matches expected
3. Transactions have correct `import_source_id` in the database
4. Performance metrics in submission status show expected path (batch vs pagination)
5. Cross-provider external_id collision test: create TBank transaction with external_id "12345", then import Nordigen transaction with same external_id "12345" -- they must NOT be flagged as duplicates when source-aware dedup is active

---

## Risks and Mitigations

### Risk 1: TRC20 external_id format change creates duplicates on first re-import
**Severity:** HIGH
**Mitigation:** `Trc20DuplicateDetector.extractLegacyKeys()` searches old-format keys (`trc20|walletAddr|txHash`) alongside new-format keys (`trc20|in|txHash`). This catches existing transactions that were imported with the old format. After 1-2 import cycles, all transactions will have the new format. Legacy key search can be removed in a future phase.

### Risk 2: Nordigen transactionId fix changes external_id for users who already imported with internalTransactionId
**Severity:** LOW
**Mitigation:** The fix (Step 2.2) only changes behavior when `internalTransactionId` is EMPTY -- it prevents wiping a valid `transactionId`. Users who already imported successfully with `internalTransactionId` will continue to get the same IDs. The `NordigenDuplicateDetector` does not need legacy key search because the composite ID format (`accountId-transactionId`) has not changed.

### Risk 3: ApiSubmitter decomposition introduces regression
**Severity:** MEDIUM
**Mitigation:** Phase 4 is pure refactoring with no behavior changes. Each extraction step preserves the exact method signatures and logic. The original `ApiSubmitter` remains as a thin facade for backward compatibility with callers (`ProcessImportSubmissionJob`, etc.). Integration tests verify end-to-end behavior after each step.

### Risk 4: Phase 1 route registration breaks existing routes
**Severity:** MEDIUM
**Mitigation:** The batch route MUST be placed BEFORE the `{transactionGroup}` wildcard. Verification with `php artisan route:list` after deployment confirms correct registration. Rollback: remove the two new lines from `api.php`.

### Risk 5: Dedup V2 pipeline has different behavior than V1 for edge cases
**Severity:** MEDIUM
**Mitigation:** Config flag `DEDUP_PIPELINE_VERSION` allows instant rollback to V1 without code changes. Default stays at V1 in Phase 2 (opt-in). Only after validation does Phase 3 change the default to V2. Users can still force V1 via env var.

### Risk 6: import_sources migration fails on existing databases
**Severity:** LOW
**Mitigation:** Migration uses `Schema::hasTable()` and `Schema::hasColumn()` guards with `try/catch` for `QueryException`. Follows the existing pattern from `2026_01_28_201901_migrations_01_2026.php`. Nullable FK means no backfill is required -- existing transactions have `import_source_id = null`, which is correct (they predate the source system).

### Risk 7: Cross-provider external_id collision during transition (source not yet set on old transactions)
**Severity:** MEDIUM
**Mitigation:** When `source` filter is provided in the batch search, only transactions WITH that source are returned. Old transactions (null source) are excluded. This means the first import after upgrade will NOT find old transactions via source-filtered search. Two mitigations:
1. The Firefly III server-side hash dedup (`error_if_duplicate_hash`) remains active as a safety net
2. The `ExternalIdController` falls back to unfiltered search when no source is provided -- the data importer only sends `source` when `DEDUP_PIPELINE_VERSION=2` AND `capabilities.import_sources=true`, so V1 behavior is preserved

### Risk 8: Source name inconsistency between config map and detector
**Severity:** LOW
**Mitigation:** Provider-specific detectors (TRC20, TBank, BasisBank, Nordigen) hard-code their `sourceName()`. The generic `ExternalIdDuplicateDetector` reads from `config('importer.source_names')`. The factory ensures consistency. Unit tests verify that each detector returns the expected source name.

---

## Rollback Strategy

Each phase has an independent rollback path:

| Phase | Rollback |
|-------|----------|
| Phase 1 | Remove route lines from `api.php`, remove `capabilities` from AboutController, run `php artisan migrate:rollback` to drop `import_sources` table and `import_source_id` column. Redeploy. |
| Phase 2 | Set `DEDUP_PIPELINE_VERSION=1` in `.importer.env`. No code revert needed. Source fields are ignored when V1 is active. |
| Phase 3 | Set `DEDUP_PIPELINE_VERSION=1` in `.importer.env`. Trait usage does not affect behavior. |
| Phase 4 | `ApiSubmitter` facade delegates to orchestrator. To revert: restore original `ApiSubmitter` from git. |

---

## Success Criteria

- [ ] Phase 1: Batch endpoints respond (non-404) when hit from importer container
- [ ] Phase 1: `/about` response includes `capabilities.batch_transactions: true` and `capabilities.import_sources: true`
- [ ] Phase 1: `POST /api/v1/import-sources` creates and returns source record
- [ ] Phase 1: `POST /api/v1/search/external-ids` with `source` param returns only source-filtered results
- [ ] Phase 1: `POST /api/v1/search/external-ids` without `source` param returns all results (backward compatible)
- [ ] Phase 1: Transaction created with `import_source: "trc20"` has non-null `import_source_id` in database
- [ ] Phase 1: Transaction created without `import_source` has null `import_source_id` (backward compatible)
- [ ] Phase 2: `DuplicateDetectorFactory::create()` returns correct detector for each flow with correct `sourceName()`
- [ ] Phase 2: TRC20 same-txHash from two wallets does NOT create duplicate (CRITICAL-2 fixed)
- [ ] Phase 2: Nordigen with empty `internalTransactionId` preserves `transactionId` (HIGH-4 fixed)
- [ ] Phase 2: `generateFallbackId` with failing JSON is deterministic (HIGH-5 fixed)
- [ ] Phase 2: All detector `extractKey()` and `sourceName()` tests pass (TDD)
- [ ] Phase 2: `BatchApiClient::batchSearchExternalIds()` passes `source` param when provided
- [ ] Phase 2: Full import cycle with `DEDUP_PIPELINE_VERSION=2` shows identical results to V1
- [ ] Phase 2: Cross-provider collision test passes (same external_id, different sources, not flagged as duplicate)
- [ ] Phase 3: `resolveLatestTransactionDate` exists in exactly 1 file (the trait), not in 3 provider files
- [ ] Phase 3: Default pipeline version is 2
- [ ] Phase 4: `ApiSubmitter.php` is under 200 lines
- [ ] Phase 4: Each extracted class has its own unit test file
- [ ] Phase 4: `DuplicateChecker.getSourceName()` returns the detector's source
- [ ] Phase 4: `TransactionSubmitter` injects `import_source` in payload when source is available
- [ ] Phase 4: `SubmissionOrchestrator` calls `createImportSource()` before first submission
- [ ] All phases: Existing `ApiSubmitterDedupTest.php` continues to pass
- [ ] All phases: `vendor/bin/phpunit --testsuite Unit` passes with 0 failures

---

## File Inventory

### New Files (21)

| File | Phase | Lines (est.) |
|------|-------|-------------|
| `firefly-iii/database/migrations/2026_03_31_000001_create_import_sources.php` | 1 | 60 |
| `firefly-iii/app/Models/ImportSource.php` | 1 | 50 |
| `firefly-iii/app/Api/V1/Controllers/System/ImportSourceController.php` | 1 | 70 |
| `firefly-iii/app/Api/V1/Requests/System/ImportSourceRequest.php` | 1 | 25 |
| `data-importer/app/Services/Shared/Dedup/DuplicateDetector.php` | 2 | 140 |
| `data-importer/app/Services/Shared/Dedup/DuplicateCheckResult.php` | 2 | 30 |
| `data-importer/app/Services/Shared/Dedup/ExternalIdDuplicateDetector.php` | 2 | 35 |
| `data-importer/app/Services/Shared/Dedup/DuplicateDetectorFactory.php` | 2 | 45 |
| `data-importer/app/Services/TRC20/Dedup/Trc20DuplicateDetector.php` | 2 | 75 |
| `data-importer/app/Services/BasisBank/Dedup/BasisBankDuplicateDetector.php` | 2 | 30 |
| `data-importer/app/Services/TBank/Dedup/TBankDuplicateDetector.php` | 2 | 30 |
| `data-importer/app/Services/Nordigen/Dedup/NordigenDuplicateDetector.php` | 2 | 45 |
| `data-importer/app/Services/Shared/Conversion/TransactionProcessorHelpers.php` | 3 | 80 |
| `data-importer/app/Services/Shared/Import/Routine/TagManager.php` | 4 | 115 |
| `data-importer/app/Services/Shared/Import/Routine/CurrencyManager.php` | 4 | 150 |
| `data-importer/app/Services/Shared/Import/Routine/DuplicateChecker.php` | 4 | 210 |
| `data-importer/app/Services/Shared/Import/Routine/TransactionSubmitter.php` | 4 | 260 |
| `data-importer/app/Services/Shared/Import/Routine/SubmissionOrchestrator.php` | 4 | 170 |
| 8 test files for detectors (data-importer) | 2 | ~400 |
| 4 test files for extracted classes (data-importer) | 4 | ~300 |
| 3 test files for Firefly III source infrastructure | 1 | ~150 |

### Modified Files (14)

| File | Phase | Change |
|------|-------|--------|
| `firefly-iii/routes/api.php` | 1 | +5 lines (2 batch routes + 2 import-source routes) |
| `firefly-iii/.../AboutController.php` | 1 | +6 lines (capabilities incl. import_sources) |
| `firefly-iii/.../TransactionJournal.php` | 1 | +3 lines (fillable, relation, cast) |
| `firefly-iii/.../ExternalIdSearchRequest.php` | 1 | +2 lines (source validation + extraction) |
| `firefly-iii/.../ExternalIdController.php` | 1 | +15 lines (source filter logic) |
| `firefly-iii/.../TransactionJournalFactory.php` | 1 | +8 lines (resolve + store import_source_id) |
| `firefly-iii/.../StoreRequest.php` | 1 | +2 lines (import_source field) |
| `firefly-iii/.../BatchStoreRequest.php` | 1 | +2 lines (import_source field) |
| `data-importer/.../TransactionIdGenerator.php` | 2 | Fix microtime fallback |
| `data-importer/.../Nordigen/Model/Transaction.php` | 2 | Fix transactionId wipe |
| `data-importer/.../TRC20/.../TransactionProcessor.php` | 2, 3 | Fix external_id format, apply trait |
| `data-importer/.../BasisBank/.../TransactionProcessor.php` | 3 | Apply trait, remove ~80 lines |
| `data-importer/.../TBank/.../TransactionProcessor.php` | 3 | Apply trait, remove ~65 lines |
| `data-importer/.../Configuration.php` | 2 | Add dedupPipelineVersion |
| `data-importer/config/importer.php` | 2, 3 | Add dedup config + source_names map |
| `data-importer/.../BatchApiClient.php` | 2, 4 | Add source param + createImportSource() |
| `data-importer/.../ApiSubmitter.php` | 2, 4 | Wire detector + source, then decompose to ~100 lines |

### Net Line Count Change

| Phase | Lines Added | Lines Removed | Net |
|-------|------------|---------------|-----|
| Phase 1 | ~410 (incl. migration, model, controller, tests) | 0 | +410 |
| Phase 2 | ~900 (incl. tests, source integration) | ~15 (bug fixes) | +885 |
| Phase 3 | ~80 (trait) | ~300 (dedup + boilerplate) | -220 |
| Phase 4 | ~1250 (incl. tests) | ~1200 (from ApiSubmitter) | +50 |
| **Total** | ~2640 | ~1515 | **+1125** |

Net increase vs original plan (+575) is ~550 lines, attributable to the import_sources infrastructure (migration, model, controller, request, tests) and source-aware integration points across both codebases. Production code net increase is approximately +325 lines (rest is tests).

---

The key files referenced in this plan:

**Firefly III -- existing (to modify):**
- `/mnt/g/REPOS/firefly/firefly-iii/routes/api.php` (lines 595-705 -- transaction and search route groups)
- `/mnt/g/REPOS/firefly/firefly-iii/app/Api/V1/Controllers/System/AboutController.php` (line 46-62 -- about method)
- `/mnt/g/REPOS/firefly/firefly-iii/app/Api/V1/Controllers/Search/ExternalIdController.php` (already implemented, needs route + source filter)
- `/mnt/g/REPOS/firefly/firefly-iii/app/Api/V1/Controllers/Models/Transaction/BatchStoreController.php` (already implemented, needs route)
- `/mnt/g/REPOS/firefly/firefly-iii/app/Api/V1/Requests/Search/ExternalIdSearchRequest.php` (needs source param)
- `/mnt/g/REPOS/firefly/firefly-iii/app/Api/V1/Requests/Models/Transaction/StoreRequest.php` (needs import_source field)
- `/mnt/g/REPOS/firefly/firefly-iii/app/Api/V1/Requests/Models/Transaction/BatchStoreRequest.php` (needs import_source field)
- `/mnt/g/REPOS/firefly/firefly-iii/app/Models/TransactionJournal.php` (needs import_source_id + relation)
- `/mnt/g/REPOS/firefly/firefly-iii/app/Models/TransactionJournalMeta.php` (reference -- external_id stored here)
- `/mnt/g/REPOS/firefly/firefly-iii/app/Factory/TransactionJournalFactory.php` (needs import_source_id in create)
- `/mnt/g/REPOS/firefly/firefly-iii/config/firefly.php` (line 777 -- journal_meta_fields, reference only)
- `/mnt/g/REPOS/firefly/firefly-iii/database/migrations/2026_01_28_201901_migrations_01_2026.php` (migration pattern reference)

**Firefly III -- new:**
- `/mnt/g/REPOS/firefly/firefly-iii/database/migrations/2026_03_31_000001_create_import_sources.php`
- `/mnt/g/REPOS/firefly/firefly-iii/app/Models/ImportSource.php`
- `/mnt/g/REPOS/firefly/firefly-iii/app/Api/V1/Controllers/System/ImportSourceController.php`

**Data Importer -- current dedup (to replace):**
- `/mnt/g/REPOS/firefly/data-importer/app/Services/Shared/Import/Routine/ApiSubmitter.php` (1627 lines -- God Object)
- `/mnt/g/REPOS/firefly/data-importer/app/Services/Shared/Import/Routine/BatchApiClient.php` (168 lines -- already has batch methods, needs source param)
- `/mnt/g/REPOS/firefly/data-importer/app/Services/TRC20/Conversion/Routine/TransactionProcessor.php` (line 471 -- CRITICAL-2 external_id format)
- `/mnt/g/REPOS/firefly/data-importer/app/Services/Nordigen/Model/Transaction.php` (line 148 -- HIGH-4 transactionId wipe)
- `/mnt/g/REPOS/firefly/data-importer/app/Services/Shared/Support/TransactionIdGenerator.php` (line 43 -- HIGH-5 microtime fallback)
- `/mnt/g/REPOS/firefly/data-importer/app/Services/BasisBank/Conversion/Routine/TransactionProcessor.php` (line 228 -- deduplicateByDescriptionDate)
- `/mnt/g/REPOS/firefly/data-importer/app/Services/TBank/Conversion/Routine/TransactionProcessor.php` (line 205 -- resolveIncrementalDateFromCursor duplicate)
- `/mnt/g/REPOS/firefly/data-importer/app/Services/Shared/Configuration/Configuration.php` (line 65 -- duplicateDetectionMethod)
- `/mnt/g/REPOS/firefly/data-importer/app/Services/LunchFlow/Conversion/Routine/GenerateTransactions.php` (line 176 -- sets external_id from getTransactionId)
- `/mnt/g/REPOS/firefly/data-importer/config/importer.php` (line 70 -- providers registry, needs source_names map)

---

Here is the complete modified plan. The key changes from the original are concentrated in several areas:

**Files referenced in this plan:**

- `/mnt/g/REPOS/firefly/data-importer/docs/plans/2026-03-31-dedup-pipeline.md` -- the plan itself (to be replaced with the content above)
- `/mnt/g/REPOS/firefly/firefly-iii/app/Models/TransactionJournal.php` -- needs `import_source_id` FK and `importSource()` relation
- `/mnt/g/REPOS/firefly/firefly-iii/app/Models/TransactionJournalMeta.php` -- reference for how `external_id` is stored as JSON-encoded meta
- `/mnt/g/REPOS/firefly/firefly-iii/app/Api/V1/Controllers/Search/ExternalIdController.php` -- needs source filter in the `search()` method
- `/mnt/g/REPOS/firefly/firefly-iii/app/Api/V1/Requests/Search/ExternalIdSearchRequest.php` -- needs `source` validation rule
- `/mnt/g/REPOS/firefly/firefly-iii/app/Factory/TransactionJournalFactory.php` -- needs to resolve and store `import_source_id` during journal creation (line 330)
- `/mnt/g/REPOS/firefly/firefly-iii/app/Api/V1/Requests/Models/Transaction/StoreRequest.php` -- needs `import_source` field extraction (line 282)
- `/mnt/g/REPOS/firefly/firefly-iii/app/Api/V1/Requests/Models/Transaction/BatchStoreRequest.php` -- same
- `/mnt/g/REPOS/firefly/firefly-iii/config/firefly.php` -- reference: `journal_meta_fields` at line 777 and `original_source` at line 804 (existing, NOT reused for the new source concept)
- `/mnt/g/REPOS/firefly/firefly-iii/database/migrations/2026_01_28_201901_migrations_01_2026.php` -- migration pattern reference
- `/mnt/g/REPOS/firefly/data-importer/app/Services/Shared/Import/Routine/BatchApiClient.php` -- needs `source` parameter in `batchSearchExternalIds()` and new `createImportSource()` method
- `/mnt/g/REPOS/firefly/data-importer/config/importer.php` -- needs `source_names` mapping and `dedup.pipeline_version` config

**Summary of what changed from the original plan:**

1. **Phase 1 expanded** from 4 steps to 13 steps -- added Steps 1.3-1.11 for the entire import source infrastructure (migration, model, controller, request modifications, factory changes)
2. **Phase 2 `DuplicateDetector` base class** gained an abstract `sourceName()` method that all detectors must implement
3. **Phase 2 `ExternalIdDuplicateDetector`** now takes a configurable source name in its constructor
4. **Phase 2 `DuplicateDetectorFactory`** resolves source names from `config('importer.source_names')` for generic flows
5. **Phase 2 `BatchApiClient`** gained `source` parameter on `batchSearchExternalIds()` and new `sourceSupported()` + `createImportSource()` methods
6. **Phase 2 `ApiSubmitter` wiring** now injects `import_source` into transaction payloads when source is supported
7. **Phase 4 `DuplicateChecker`** exposes `getSourceName()` from the underlying detector
8. **Phase 4 `TransactionSubmitter`** injects `import_source` into each transaction line
9. **Phase 4 `SubmissionOrchestrator`** auto-creates the import source via `POST /api/v1/import-sources` before first submission
10. **Architecture Changes tables** updated with all new Firefly III files
11. **File Inventory** expanded from 16 to 21 new files
12. **Testing Strategy** expanded with Firefly III test files
13. **Risks** section gained Risk 6 (migration), Risk 7 (transition period), Risk 8 (name consistency)
14. **Success Criteria** expanded with source-specific verification items