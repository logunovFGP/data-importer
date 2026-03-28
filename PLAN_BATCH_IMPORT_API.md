# PLAN: Batch Import API for Performance Optimization

## Problem

The import submission pipeline takes ~10.6 minutes for 2,124 transactions due to sequential HTTP API calls:
- 348 individual POSTs to `POST /v1/transactions` at 1.2s each = 420s (66%)
- 940 individual search GETs for dedup at 229ms each = 215s (34%)
- Total: ~637s

## Solution

Add batch endpoints to Firefly III core, use them in the data-importer. Target: ~25 seconds.

## Architecture

| Change | File | Description |
|--------|------|-------------|
| New controller | `firefly-iii/app/Api/V1/Controllers/Models/Transaction/BatchStoreController.php` | Batch transaction creation |
| New request | `firefly-iii/app/Api/V1/Requests/Models/Transaction/BatchStoreRequest.php` | Validates array-of-payloads |
| New controller | `firefly-iii/app/Api/V1/Controllers/Search/ExternalIdController.php` | Batch external_id lookup |
| New request | `firefly-iii/app/Api/V1/Requests/Search/ExternalIdSearchRequest.php` | Validates external_id array |
| Modified routes | `firefly-iii/routes/api.php` | Two new route registrations |
| Modified about | `firefly-iii/app/Api/V1/Controllers/System/AboutController.php` | Add `capabilities` to response |
| New class | `data-importer/app/Services/Shared/Import/Routine/BatchApiClient.php` | Batch HTTP client |
| Modified class | `data-importer/app/Services/Shared/Import/Routine/ApiSubmitter.php` | Uses batch endpoints when available |

---

## Phase 1: Batch Transaction Create Endpoint (Firefly III Core)

### Step 1.1: Create BatchStoreRequest

**File:** `firefly-iii/app/Api/V1/Requests/Models/Transaction/BatchStoreRequest.php` (NEW)

Input schema:
```json
{
  "batch": [
    {
      "error_if_duplicate_hash": true,
      "apply_rules": false,
      "fire_webhooks": false,
      "transactions": [
        { "type": "withdrawal", "date": "2026-01-01", "amount": "100", ... }
      ]
    }
  ]
}
```

Validation:
- `batch`: `required|array|max:100`
- `batch.*.transactions`: `required|array|min:1`
- Reuse existing `StoreRequest` per-transaction-split rules prefixed with `batch.*`
- Per-item semantic validation (accounts, currencies) deferred to controller loop so one bad item doesn't reject the batch

### Step 1.2: Create BatchStoreController

**File:** `firefly-iii/app/Api/V1/Controllers/Models/Transaction/BatchStoreController.php` (NEW)

Algorithm:
1. Get validated items from `$request->getAll()['items']`
2. For each item (indexed 0..N-1):
   a. Wrap in `DB::transaction()` — each item is its own DB transaction (partial success)
   b. Call `$this->groupRepository->store($data)` (same as existing `StoreController::store()`)
   c. On success: collect via `GroupCollector`, transform, return `{index, status: "success", external_id, data}`
   d. On `DuplicateTransactionException`: return `{index, status: "duplicate", external_id, errors}`
   e. On `FireflyException`/`ValidationException`: return `{index, status: "error", external_id, errors}`
3. Return `{results: [...]}` with HTTP 200

Response format (error contract — includes external_id + index for retry mapping):
```json
{
  "results": [
    {
      "index": 0,
      "status": "success",
      "external_id": "OD518865726",
      "data": { "type": "transactions", "id": "123", "attributes": {...} }
    },
    {
      "index": 1,
      "status": "duplicate",
      "external_id": "OD518865727",
      "errors": { "transactions.0.description": ["Duplicate of transaction #42"] }
    },
    {
      "index": 2,
      "status": "error",
      "external_id": "OD519128316",
      "errors": { "transactions.0.currency_code": ["Currency \"XYZ\" is unknown."] }
    }
  ]
}
```

Request size guard:
```php
// Middleware or controller check
if ($request->header('Content-Length') > 5 * 1024 * 1024) { // 5MB max
    return response()->json(['message' => 'Batch request too large. Max 5MB.'], 413);
}
```

Constructor: Same pattern as existing `StoreController` (inject `TransactionGroupRepositoryInterface`).

### Step 1.3: Register the batch route

**File:** `firefly-iii/routes/api.php` (MODIFY)

Insert at line 605, BEFORE the `{transactionGroup}` wildcard:
```php
Route::post('batch', ['uses' => 'BatchStoreController@store', 'as' => 'batch-store'])
    ->middleware('throttle:10,1');
```

Full path: `POST /api/v1/transactions/batch`

CRITICAL: Must be placed before `{transactionGroup}` wildcard or Laravel resolves "batch" as a group ID.

---

## Phase 2: Batch External ID Search Endpoint (Firefly III Core)

### Step 2.1: Create ExternalIdSearchRequest

**File:** `firefly-iii/app/Api/V1/Requests/Search/ExternalIdSearchRequest.php` (NEW)

Input:
```json
{
  "external_ids": ["OD518865726", "OD518865727", ...],
  "start_date": "2026-01-01",
  "end_date": "2026-03-31"
}
```

Validation:
- `external_ids`: `required|array|min:1|max:500`
- `external_ids.*`: `required|string|min:1|max:255`
- `start_date`: `nullable|date`
- `end_date`: `nullable|date|after_or_equal:start_date`

### Step 2.2: Create ExternalIdController

**File:** `firefly-iii/app/Api/V1/Controllers/Search/ExternalIdController.php` (NEW)

SQL query:
```php
// CRITICAL: use same JSON encoding flags as store path to avoid mismatches
$jsonEncodedIds = array_map(
    fn(string $id) => json_encode($id, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    $externalIds
);

$results = DB::table('journal_meta')
    ->join('transaction_journals', 'journal_meta.transaction_journal_id', '=', 'transaction_journals.id')
    ->join('transaction_groups', 'transaction_journals.transaction_group_id', '=', 'transaction_groups.id')
    ->join('transactions as t_source', function ($join) {
        $join->on('t_source.transaction_journal_id', '=', 'transaction_journals.id')
             ->where('t_source.amount', '<', 0);
    })
    ->join('transactions as t_dest', function ($join) {
        $join->on('t_dest.transaction_journal_id', '=', 'transaction_journals.id')
             ->where('t_dest.amount', '>', 0);
    })
    ->where('journal_meta.name', '=', 'external_id')
    ->whereIn('journal_meta.data', $jsonEncodedIds)
    ->whereNull('journal_meta.deleted_at')
    ->whereNull('transaction_journals.deleted_at')
    ->where('transaction_groups.user_group_id', '=', $userGroup->id)
    ->select([...]);
```

Optional date range filter when `start_date`/`end_date` provided.

Response:
```json
{
  "results": {
    "OD518865726": {
      "transaction_group_id": 123,
      "description": "Private Transfer",
      "amount": "150.00",
      "source_account_id": 1,
      "destination_account_id": 45
    },
    "OD518865727": null
  }
}
```

JSON encoding defensiveness: Both store (when writing `journal_meta.data`) and search use `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES` to ensure identical encoding. Integration test required with unicode IDs (Georgian: `გადარიცხვა-001`), forward slashes (`TX/2026/001`), and backslashes (`TX\001`).

### Step 2.3: Register the search route

**File:** `firefly-iii/routes/api.php` (MODIFY)

Inside the existing search route group (lines 694-704):
```php
Route::post('external-ids', ['uses' => 'ExternalIdController@search', 'as' => 'external-ids']);
```

Full path: `POST /api/v1/search/external-ids`

---

## Phase 3: Capabilities Detection (Firefly III Core)

### Step 3.1: Add capabilities to About endpoint

**File:** `firefly-iii/app/Api/V1/Controllers/System/AboutController.php` (MODIFY)

Add to the `$data` array:
```php
'capabilities' => [
    'batch_transactions'        => true,
    'batch_external_id_search'  => true,
],
```

Why: Version-string parsing is fragile (develop builds, branch prefixes). Explicit capabilities map is unambiguous and extensible.

---

## Phase 4: Update Data Importer

### Step 4.1: Create BatchApiClient

**File:** `data-importer/app/Services/Shared/Import/Routine/BatchApiClient.php` (NEW)

```php
class BatchApiClient
{
    public function __construct(string $baseUrl, string $token, float $timeout, bool|string $verify)

    public function batchStoreTransactions(array $items): array
    // POST /api/v1/transactions/batch

    public function batchSearchExternalIds(array $externalIds, ?string $startDate, ?string $endDate): array
    // POST /api/v1/search/external-ids

    public function supportsBatchEndpoints(): bool
    // GET /api/v1/about → check capabilities.batch_transactions
}
```

Uses Laravel `Http` facade (not vendor `Request` class) — matches existing pattern in `ApiSubmitter::fetchExistingExternalIds()`.

### Step 4.2: Add capability detection to ApiSubmitter

**File:** `data-importer/app/Services/Shared/Import/Routine/ApiSubmitter.php` (MODIFY)

New properties:
```php
private bool $batchEndpointsAvailable = false;
private ?BatchApiClient $batchClient = null;
```

In `setImportJob()`:
```php
$this->batchClient = new BatchApiClient(...);
$this->batchEndpointsAvailable = $this->batchClient->supportsBatchEndpoints();
```

### Step 4.3: Replace warmExternalIdDuplicateIndex with batch search

**File:** `data-importer/app/Services/Shared/Import/Routine/ApiSubmitter.php` (MODIFY method at line 925)

When `$batchEndpointsAvailable`:
1. Extract all external_ids from import lines
2. Single call: `batchClient->batchSearchExternalIds($ids, $startDate, $endDate)`
3. Transform response into same `preloadedDuplicateIndex` structure
4. All downstream code (`uniqueTransaction()`, `findDuplicateMatchForAccountContext()`) works unchanged

Fallback: existing paginated fetch when batch unavailable.

### Step 4.4: Replace per-transaction POST loop with batch POSTs

**File:** `data-importer/app/Services/Shared/Import/Routine/ApiSubmitter.php` (MODIFY method at line 98)

Adaptive chunk sizing:
```php
private function estimateChunkSize(array $lines): int
{
    if ([] === $lines) {
        return 100;
    }
    $sample = array_slice($lines, 0, min(10, count($lines)));
    $avgSplits = array_sum(array_map(
        fn($l) => count($l['line']['transactions'] ?? []),
        $sample
    ));
    $avgSplits = max(1, $avgSplits / count($sample));
    // Target: ~100 transaction splits per batch
    return max(10, min(100, (int) floor(100 / $avgSplits)));
}
```

Batch submission flow:
```
Phase A: Duplicate pre-check (local, using preloaded index)
  → Filter out known duplicates

Phase B: Batch submission in adaptive chunks
  → For each chunk: POST to batch endpoint
  → Process per-item results (success/duplicate/error)
  → Remember external_ids from successes
  → Add tags to created groups

Phase C: Currency retry
  → Collect items that failed due to unknown currency
  → Create missing currencies
  → Re-submit as second batch
```

Idempotent batch retry (on timeout/connection error):
```
1. Extract external_ids from the failed batch
2. Call batchSearchExternalIds() for those IDs
3. Remove items that were already created (committed before timeout)
4. Re-submit only the remaining items
5. Log: "Batch retry: {n}/{total} already committed, resubmitting {remaining}"
```

### Step 4.5: Handle currency retry in batch mode

After processing a batch response, collect items that failed with currency validation errors:
1. Parse currency codes from error messages
2. Call existing `createMissingCurrenciesFromValidationErrors()`
3. Re-submit failed items as a second batch
4. Items that fail again are logged as errors (no infinite retry)

---

## Expected Performance

| Metric | Current | With Batching | Speedup |
|--------|---------|---------------|---------|
| Dedup checks | 940 calls × 229ms = 215s | 2 batch calls × ~1s = 2s | 107x |
| Submissions | 348 calls × 1.2s = 420s | 4 batch calls × ~5s = 20s | 21x |
| **Total** | **~637s (10.6 min)** | **~25s** | **~25x** |

---

## Risk Matrix

| Risk | Severity | Mitigation |
|------|----------|------------|
| Partial batch commit + timeout → duplicates | Addressed | Idempotent retry: re-check external_ids before resubmit |
| JSON encoding mismatch on unicode IDs | Addressed | `JSON_UNESCAPED_UNICODE\|JSON_UNESCAPED_SLASHES` both sides; integration test |
| Large payload memory exhaustion | Addressed | 5MB content-length guard + adaptive chunk sizing |
| 100 DB::transaction commits per request | Low | Acceptable for correctness; MariaDB handles fine |
| `journal_meta` index missing | Low | Single `WHERE IN` still faster than 940 HTTP calls |
| Rate limiting abuse | Low | `throttle:10,1` middleware on batch route |
| Old importer + new Firefly III | None | Old importers never call batch endpoints |
| New importer + old Firefly III | None | Capability detection falls back to single-transaction mode |

---

## Testing Strategy

### Firefly III Core
- `BatchStoreControllerTest`: 3 valid → 3 success; 1 duplicate in 3 → 2 success + 1 duplicate; max 100 enforced
- `ExternalIdControllerTest`: 3 IDs where 2 exist → 2 hits + 1 null; unicode/slash IDs match correctly
- Integration: create via batch, verify via `GET /v1/transactions/{id}`; verify partial failure doesn't rollback others

### Data Importer
- `BatchApiClientTest`: Mock HTTP, verify URL construction and response parsing
- `ApiSubmitterBatchTest`: Mock BatchApiClient, verify dedup pre-check, chunk sizing, currency retry
- E2E: Import 100 transactions via batch, verify all in Firefly III, compare timing

---

## Checklist

- [ ] Phase 1: `POST /api/v1/transactions/batch` accepts up to 100 payloads, returns per-item results with index + external_id
- [ ] Phase 1: 5MB content-length guard, throttle:10,1 middleware
- [ ] Phase 2: `POST /api/v1/search/external-ids` accepts up to 500 IDs, single SQL query
- [ ] Phase 2: JSON encoding normalized with `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`
- [ ] Phase 3: `GET /api/v1/about` returns `capabilities.batch_transactions: true`
- [ ] Phase 4: Importer auto-detects batch support via capabilities
- [ ] Phase 4: Adaptive chunk sizing based on split count
- [ ] Phase 4: Idempotent retry on timeout (re-check external_ids before resubmit)
- [ ] Phase 4: Currency retry as second batch pass
- [ ] Phase 4: Graceful fallback to single-transaction mode
- [ ] All tests pass with 80%+ coverage on new code
- [ ] Import of 2,124 transactions completes in under 2 minutes
