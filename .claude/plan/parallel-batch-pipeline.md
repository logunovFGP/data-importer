# Plan: Parallel Batch Pipeline — Fetch + Submit Simultaneously with Live Transaction Board

## Problem

Current TRC20 pipeline is strictly sequential:
```
Step 1: Fetch ALL transactions from TronGrid (may take minutes for large wallets)
Step 2: Convert ALL transactions to Firefly III format
Step 3: Submit ALL transactions to Firefly III one-by-one
```

The user waits through step 1 with no visible progress, then step 3 with only aggregate progress. No individual transaction visibility during processing.

## Solution: Parallel Batch Pipeline

```
┌──────────────────┐     ┌──────────────────┐
│ FETCH (TronGrid) │     │ SUBMIT (Firefly) │
│                  │     │                  │
│ Page 1 → 200 tx  ├────►│ Batch 1: 100 tx  │
│ Page 2 → 200 tx  │     │ (submitting...)  │
│ Page 3 → 143 tx  ├────►│ Batch 2: 100 tx  │
│ (done, 543 total)│     │ Batch 3: 100 tx  │
│                  │     │ Batch 4: 100 tx  │
│                  │     │ Batch 5: 43 tx   │
│                  │     │ Batch 6: 100 tx  │
│                  │     │ (done)           │
└──────────────────┘     └──────────────────┘

Live Transaction Board:
┌─────────────────────────────────────────────────────────────┐
│ Processed 432 transactions (showing last 100)               │
│ ... and 332 more transactions processed                     │
├─────────────────────────────────────────────────────────────┤
│ ✅ ea8793... │ 1090.98 USDT │ TDjet→TT65L │ 2025-12-15  │
│ ✅ 71958f... │   11.40 USDT │ TDjet→TT65L │ 2025-12-14  │
│ ⏳ 044230... │  500.00 USDT │ TU4ve→TT65L │ 2025-12-13  │
│ ⏳ a3b2c1... │   25.50 USDT │ TT65L→TXyz1 │ 2025-12-12  │
│ ...                                                         │
└─────────────────────────────────────────────────────────────┘
```

### Architecture: Batch Buffer with Parallel Processing

**Batch size**: 100 transactions (configurable via `trc20.batch_size`)

**Flow**:
1. API fetches a page (up to 200 rows from TronGrid)
2. Rows are normalized and added to a **batch buffer**
3. When buffer reaches 100 (or API is done), a batch is dispatched
4. Each batch is: normalized → converted to Firefly format → submitted to Firefly III API
5. Meanwhile, API continues fetching next page
6. Status object tracks per-transaction results for the live board

**Not true parallelism** (PHP is single-threaded): The "parallel" effect comes from interleaving — process a batch, then fetch next page, process next batch. The user sees continuous progress instead of long silent waits.

### Backend: Transaction Board Status

Add a `transaction_board` field to `ConversionStatus`:

```php
public array $transactionBoard = [];

// Each entry:
[
    'tx_id'       => 'ea8793...',      // Short transaction ID
    'amount'      => '1090.98',        // Human-readable amount
    'currency'    => 'USDT',           // Token symbol
    'direction'   => 'incoming',       // incoming/outgoing
    'counterparty' => 'TDjet...Kpx',  // Short address
    'date'        => '2025-12-15',     // Transaction date
    'status'      => 'fetched',        // fetched|converting|submitted|duplicate|error
    'message'     => '',               // Error message if any
]

// Overflow: keep only last 100 in board, track total count
public int $transactionBoardTotal = 0;
public int $transactionBoardHidden = 0; // = total - count(board)
```

### Frontend: Live Transaction Board

Below the progress bar on the conversion page, show a scrollable table:

```html
<div x-show="transactionBoard.length > 0" class="card mt-3">
    <div class="card-header">
        Transaction board
        <span class="badge bg-secondary" x-text="transactionBoardTotal + ' total'"></span>
    </div>
    <div class="card-body p-0">
        <div x-show="transactionBoardHidden > 0" class="text-center py-2 bg-body-secondary">
            <small class="text-muted" x-text="'... and ' + transactionBoardHidden + ' more transactions processed'"></small>
        </div>
        <table class="table table-sm table-striped mb-0" style="font-size: 0.85rem;">
            <thead><tr>
                <th>Status</th><th>TX ID</th><th>Amount</th><th>Direction</th><th>Counterparty</th><th>Date</th>
            </tr></thead>
            <tbody>
                <template x-for="tx in transactionBoard">
                    <tr>
                        <td><!-- status icon based on tx.status --></td>
                        <td x-text="tx.tx_id"></td>
                        <td x-text="tx.amount + ' ' + tx.currency"></td>
                        <td x-text="tx.direction"></td>
                        <td x-text="tx.counterparty"></td>
                        <td x-text="tx.date"></td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
</div>
```

Status icons:
- `fetched` → blue download icon
- `converting` → spinning cog
- `submitted` → green check
- `duplicate` → yellow skip
- `error` → red X

## Implementation Steps

### Phase 1: Add `transactionBoard` to ConversionStatus

**File:** `app/Services/Shared/Conversion/ConversionStatus.php` (MODIFY)
- Add `$transactionBoard`, `$transactionBoardTotal`, `$transactionBoardHidden`
- Add `addBoardEntry(array $entry)` method — appends entry, trims to 100, updates hidden count
- Add `updateBoardEntryStatus(string $txId, string $status, string $message = '')` — updates existing entry
- Include in `toArray()` / `fromArray()`

### Phase 2: Restructure TRC20 TransactionProcessor for batch processing

**File:** `app/Services/TRC20/Conversion/Routine/TransactionProcessor.php` (MODIFY)

Change `downloadWalletTransactions()` to process batches inline:

```php
private function downloadWalletTransactions(...): array
{
    $buffer = [];
    $allProcessed = [];
    $batchSize = (int)config('trc20.batch_size', 100);

    while ($page < $maxPages) {
        $response = $request->get($dateFrom, $dateTo, $cursor);
        $rows = $this->extractWalletTransactions($response, $wallet);
        $buffer = array_merge($buffer, $rows);

        // Add fetched transactions to board
        foreach ($rows as $tx) {
            $this->importJob->conversionStatus->addBoardEntry([
                'tx_id'        => substr($tx->getTransactionId(), 0, 10) . '...',
                'amount'       => $tx->amount,
                'currency'     => $tx->currency,
                'direction'    => (float)$tx->amount < 0 ? 'outgoing' : 'incoming',
                'counterparty' => substr($tx->merchant, 0, 8) . '...' . substr($tx->merchant, -4),
                'date'         => $tx->date,
                'status'       => 'fetched',
            ]);
        }
        $this->saveConversionStatus();

        // When buffer reaches batch size or no more pages, process the batch
        if (count($buffer) >= $batchSize || !$response->hasNextCursor()) {
            $this->importJob->conversionStatus->addActivity(
                sprintf('Processing batch of %d transactions...', count($buffer))
            );
            $allProcessed = array_merge($allProcessed, $buffer);
            $buffer = [];
        }

        // Pagination
        if (!$response->hasNextCursor()) break;
        $cursor = $response->getNextCursor();
    }

    return $allProcessed;
}
```

### Phase 3: Add board entries during submission

**File:** `app/Services/Shared/Import/Routine/ApiSubmitter.php` (MODIFY)

After each transaction is submitted/skipped, update the board:

```php
// After successful submission:
$this->importJob->submissionStatus->updateBoardEntry($externalId, 'submitted');

// After duplicate skip:
$this->importJob->submissionStatus->updateBoardEntry($externalId, 'duplicate');

// After error:
$this->importJob->submissionStatus->updateBoardEntry($externalId, 'error', $errorMessage);
```

Also add `transactionBoard` to `SubmissionStatus` (same structure as ConversionStatus).

### Phase 4: Frontend — render transaction board on conversion page

**File:** `resources/views/v2/import/007-convert/index.blade.php` (MODIFY)
- Add transaction board table (shown when `transactionBoard.length > 0`)
- Overflow indicator: `"... and {hidden} more transactions processed"`
- Status icons per row

**File:** `resources/js/v2/src/pages/conversion/index.js` (MODIFY)
- Add `transactionBoard: []`, `transactionBoardTotal: 0`, `transactionBoardHidden: 0`
- Parse from poll response
- Auto-scroll table to bottom

### Phase 5: Frontend — render transaction board on submission page

**File:** `resources/views/v2/import/008-submit/index.blade.php` (MODIFY)
**File:** `resources/js/v2/src/pages/submit/index.js` (MODIFY)
- Same board rendering as conversion page

### Phase 6: Rebuild Vite assets

Required after JS changes.

## Config

**File:** `config/trc20.php` (MODIFY)
```php
'batch_size' => (int) env('TRC20_BATCH_SIZE', 100),
```

## Key Files

| File | Operation | Description |
|------|-----------|-------------|
| `app/Services/Shared/Conversion/ConversionStatus.php` | MODIFY | Add transactionBoard + overflow tracking |
| `app/Services/Shared/Import/Status/SubmissionStatus.php` | MODIFY | Same board for submission |
| `app/Services/TRC20/Conversion/Routine/TransactionProcessor.php` | MODIFY | Batch buffer + board entries during fetch |
| `app/Services/Shared/Import/Routine/ApiSubmitter.php` | MODIFY | Board entry updates during submission |
| `resources/views/v2/import/007-convert/index.blade.php` | MODIFY | Transaction board table |
| `resources/views/v2/import/008-submit/index.blade.php` | MODIFY | Transaction board table |
| `resources/js/v2/src/pages/conversion/index.js` | MODIFY | Parse + render board |
| `resources/js/v2/src/pages/submit/index.js` | MODIFY | Parse + render board |
| `config/trc20.php` | MODIFY | Add batch_size config |

## Risks

| Risk | Mitigation |
|------|------------|
| Board grows too large in memory/JSON | Cap at 100 entries, track hidden count |
| Status JSON size increases with board | Board entries are ~200 bytes each, 100 max = ~20KB |
| Board entry updates (by tx_id) are O(n) | Array is capped at 100, linear scan is fast enough |
| Other providers don't have board entries | Board only shows when entries exist (x-show guard) |

---

## Design System: Transaction Board Component

### Design Audit — Existing Patterns

The importer uses Bootstrap 5.3 with these established component patterns:

| Pattern | Bootstrap Class | Usage |
|---------|----------------|-------|
| Status badges | `badge bg-success/bg-danger/bg-primary/bg-secondary` | Pull checklist account status |
| List groups | `list-group` + `list-group-item` | Pull checklist account items |
| Progress bars | `progress` + `progress-bar-striped progress-bar-animated` | Overall + nested pull progress |
| Cards | `card` + `card-header` + `card-body` | Every section wrapper |
| Tables | `table table-sm table-striped` | Mapping, roles, recent imports |
| Flex layout | `d-flex justify-content-between align-items-center` | List item content |
| Muted text | `text-muted`, `small` | Secondary info, timestamps |
| Custom colors | Primary `#1E6581`, Success `#64B624`, Danger `#CD5029` | Buttons, alerts |
| Font | Roboto sans-serif | All text |
| Dark mode | `data-bs-theme` + `var(--bs-body-bg)` | Auto via Bootstrap |

### Transaction Board — Component Design

**File:** `resources/views/v2/components/transaction-board.blade.php` (NEW)

Extracted as a **shared Blade component** used by both 007-convert and 008-submit views, identical to how `step-navigation.blade.php` was extracted.

**Props:**
- `$boardDataVar` — Alpine.js variable name for the board array (default: `transactionBoard`)
- `$totalVar` — Alpine.js variable name for total count (default: `transactionBoardTotal`)
- `$hiddenVar` — Alpine.js variable name for hidden count (default: `transactionBoardHidden`)

**Visual Hierarchy:**

```
┌─────────────────────────────────────────────────────────────────┐
│ ▶ Transaction board                    [345 total] [Collapse ▼] │  ← card-header
├─────────────────────────────────────────────────────────────────┤
│ ┄ ... and 245 more transactions processed                       │  ← overflow (bg-body-secondary)
├─────────────────────────────────────────────────────────────────┤
│ ✅ ea8793..49  │  1,090.98 USDT  │ ← TDjet..Kpx  │ 2025-12-15 │  ← table rows
│ ✅ 71958f..c1  │     11.40 USDT  │ ← TDjet..Kpx  │ 2025-12-14 │
│ ⏳ 044230..38  │    500.00 USDT  │ → TU4ve..vaa  │ 2025-12-13 │
│ ❌ a3b2c1..f2  │     25.50 TRX   │ ← TXyz1..abc  │ 2025-12-12 │
│ 🔄 pending...  │                 │                │            │
└─────────────────────────────────────────────────────────────────┘
```

**Status Icon Mapping** (Font Awesome 7, consistent with existing usage):

| Status | Icon | Color | Badge Class |
|--------|------|-------|-------------|
| `fetched` | `fa-download` | Blue | `text-primary` |
| `converting` | `fa-cog fa-spin` | Primary | `text-primary` |
| `submitted` | `fa-check-circle` | Green | `text-success` |
| `duplicate` | `fa-forward` | Yellow | `text-warning` |
| `error` | `fa-times-circle` | Red | `text-danger` |
| `pending` | `fa-clock` | Muted | `text-muted` |

These match the existing `getPullStatusBadgeClass()` color mapping:
- done → `bg-success` (green)
- error → `bg-danger` (red)
- running → `bg-primary` (blue)
- default → `bg-secondary` (gray)

**Typography:**
- Table font: `0.8rem` (matches activity log, slightly smaller than default body)
- TX ID: `font-family: monospace` (code-like, 10 chars + `...`)
- Amount: right-aligned, monospace
- Counterparty: monospace, with direction arrow (← incoming, → outgoing)

**Spacing:**
- Card margin: `mt-3` (consistent with other cards)
- Table padding: `table-sm` (compact rows)
- Overflow banner: `py-2` centered text

**Responsive:**
- Table wraps with `table-responsive` wrapper
- On mobile, TX ID and counterparty truncate naturally via monospace sizing
- Collapse/expand preserves state via Alpine.js `boardExpanded` toggle

### Component Template

```blade
@props([
    'boardDataVar' => 'transactionBoard',
    'totalVar' => 'transactionBoardTotal',
    'hiddenVar' => 'transactionBoardHidden',
])

<div class="card mt-3" x-show="{{ $boardDataVar }}.length > 0">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>
            Transaction board
            <span class="badge bg-secondary ms-1" x-text="{{ $totalVar }} + ' total'"></span>
        </span>
        <button class="btn btn-sm btn-outline-secondary" type="button"
                @click="boardExpanded = !boardExpanded"
                x-text="boardExpanded ? 'Collapse' : 'Expand'">
        </button>
    </div>
    <div x-show="boardExpanded" x-transition>
        <div x-show="{{ $hiddenVar }} > 0"
             class="text-center py-2 bg-body-secondary border-bottom">
            <small class="text-muted">
                <span class="fas fa-ellipsis-h me-1"></span>
                and <strong x-text="{{ $hiddenVar }}"></strong> more transactions processed
            </small>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-striped mb-0" style="font-size: 0.8rem;">
                <thead>
                    <tr class="text-muted">
                        <th style="width: 2rem;"></th>
                        <th>TX ID</th>
                        <th class="text-end">Amount</th>
                        <th>Dir</th>
                        <th>Counterparty</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="tx in {{ $boardDataVar }}" :key="tx.tx_id">
                        <tr>
                            <td>
                                <span x-show="tx.status === 'fetched'" class="fas fa-download text-primary" title="Fetched"></span>
                                <span x-show="tx.status === 'converting'" class="fas fa-cog fa-spin text-primary" title="Converting"></span>
                                <span x-show="tx.status === 'submitted'" class="fas fa-check-circle text-success" title="Submitted"></span>
                                <span x-show="tx.status === 'duplicate'" class="fas fa-forward text-warning" title="Duplicate"></span>
                                <span x-show="tx.status === 'error'" class="fas fa-times-circle text-danger" :title="tx.message || 'Error'"></span>
                                <span x-show="tx.status === 'pending'" class="fas fa-clock text-muted" title="Pending"></span>
                            </td>
                            <td><code x-text="tx.tx_id" style="font-size: 0.75rem;"></code></td>
                            <td class="text-end font-monospace" x-text="tx.amount + ' ' + tx.currency"></td>
                            <td>
                                <span x-show="tx.direction === 'incoming'" class="text-success">←</span>
                                <span x-show="tx.direction === 'outgoing'" class="text-danger">→</span>
                            </td>
                            <td><code x-text="tx.counterparty" style="font-size: 0.75rem;"></code></td>
                            <td class="text-muted" x-text="tx.date"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>
</div>
```

### Dark Mode Compatibility

All colors use Bootstrap semantic classes (`text-primary`, `text-success`, `bg-body-secondary`), not inline hex values. The `var(--bs-body-bg)` background on the overflow banner auto-adapts. No inline `style="color:..."` needed.

### Component Extraction Strategy

The `transaction-board.blade.php` component is:
1. **Self-contained** — no external JS dependencies, reads from Alpine.js data bindings via prop names
2. **Reusable** — same component on both 007-convert and 008-submit
3. **Decoupled** — the backend populates `transactionBoard` array, the component just renders it
4. **Provider-agnostic** — any provider can populate board entries, not just TRC20

Include in views:
```blade
@include('components.transaction-board', [
    'boardDataVar' => 'transactionBoard',
    'totalVar' => 'transactionBoardTotal',
    'hiddenVar' => 'transactionBoardHidden',
])
```

### Key Files (appended to main plan)

| File | Operation | Description |
|------|-----------|-------------|
| `resources/views/v2/components/transaction-board.blade.php` | NEW | Shared board component |

## Checklist

- [ ] Phase 1: Add transactionBoard to ConversionStatus + SubmissionStatus
- [ ] Phase 2: TRC20 TransactionProcessor batch buffer with board entries
- [ ] Phase 3: ApiSubmitter board entry updates during submission
- [ ] Phase 4: Create `transaction-board.blade.php` shared component
- [ ] Phase 5: Include board on conversion page (007) + parse in JS
- [ ] Phase 6: Include board on submission page (008) + parse in JS
- [ ] Phase 7: Rebuild Vite assets
- [ ] Phase 8: Verify dark mode, responsive, overflow indicator
- [ ] Phase 5: Frontend transaction board on submission page
- [ ] Phase 6: Rebuild Vite assets
