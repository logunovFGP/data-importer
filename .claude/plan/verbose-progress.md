# Plan: Verbose Progress — Live Transaction Feed During Conversion & Submission

## Problem

During conversion (step 007) and submission (step 008), the user sees a progress bar but no detail about **what** is happening. The progress bar moves silently — if it pauses for 30 seconds, the user can't tell if it's stuck or processing a large batch.

## Current State

**Conversion (007):**
- Shows per-account progress bars with status badges
- Shows total accounts pulled: `{done} of {total}`
- Shows elapsed time
- Does NOT show: individual transactions, batch progress, API page numbers

**Submission (008):**
- Shows `{current} / {total} transactions`
- Shows performance table (calls, avg ms, total ms)
- Shows unique/duplicate counts
- Does NOT show: which transactions are being processed, batch IDs

## Solution: Live Activity Log

Add a scrolling activity log below the progress bar that shows real-time processing details. This is a text feed that auto-scrolls to the bottom, showing what the backend is doing.

### Conversion Step (007) — Activity Log

Backend already tracks per-account meta. Enhance with transaction-level detail:

**During pull phase:**
```
[14:23:01] Wallet TT65LR...V8Mhd: Fetching page 1 from TronGrid...
[14:23:02] Wallet TT65LR...V8Mhd: Received 200 transactions (page 1)
[14:23:03] Wallet TT65LR...V8Mhd: Fetching page 2 from TronGrid...
[14:23:04] Wallet TT65LR...V8Mhd: Received 143 transactions (page 2, final)
[14:23:04] Wallet TT65LR...V8Mhd: Total 343 transactions fetched
```

**During normalization phase:**
```
[14:23:05] Processing transactions 1-100 of 343...
[14:23:06] Processing transactions 101-200 of 343...
[14:23:06] Processing transactions 201-300 of 343...
[14:23:07] Processing transactions 301-343 of 343...
[14:23:07] Normalization complete: 312 valid, 31 filtered (non-USDT)
```

### Submission Step (008) — Activity Log

```
[14:23:10] Submitting batch 1/4 (transactions 1-100 of 312)...
[14:23:15] Batch 1 complete: 98 imported, 2 duplicates (avg 50ms/tx)
[14:23:15] Submitting batch 2/4 (transactions 101-200 of 312)...
[14:23:20] Batch 2 complete: 100 imported, 0 duplicates (avg 48ms/tx)
[14:23:20] Submitting batch 3/4 (transactions 201-300 of 312)...
[14:23:25] Batch 3 complete: 97 imported, 3 duplicates (avg 52ms/tx)
[14:23:25] Submitting batch 4/4 (transactions 301-312 of 312)...
[14:23:26] Batch 4 complete: 12 imported, 0 duplicates (avg 45ms/tx)
[14:23:27] Applying tags...
[14:23:27] Import complete: 307 imported, 5 duplicates
```

## Architecture

### Backend: Add `activity_log` array to ConversionStatus and SubmissionStatus

Both status objects get a new field:
```php
private array $activityLog = [];

public function addActivity(string $message): void
{
    $this->activityLog[] = [
        'time' => now()->format('H:i:s'),
        'message' => $message,
    ];
    // Keep only last 200 entries to avoid memory bloat
    if (count($this->activityLog) > 200) {
        $this->activityLog = array_slice($this->activityLog, -200);
    }
}

public function toArray(): array
{
    return [
        // ...existing fields...
        'activity_log' => $this->activityLog,
    ];
}
```

### Backend: Add activity messages at key points

**In TRC20 TransactionProcessor (and other providers):**

| Location | Message |
|----------|---------|
| Before API call | `"Wallet {short}: Fetching page {n} from TronGrid..."` |
| After API response | `"Wallet {short}: Received {count} transactions (page {n})"` |
| After all pages | `"Wallet {short}: Total {count} transactions fetched"` |
| During normalization | `"Processing transactions {from}-{to} of {total}..."` |
| After normalization | `"Normalization complete: {valid} valid, {filtered} filtered"` |

**In ApiSubmitter:**

| Location | Message |
|----------|---------|
| Before batch submit | `"Submitting batch {n}/{total} (transactions {from}-{to})..."` |
| After batch response | `"Batch {n}: {imported} imported, {dups} duplicates (avg {ms}ms/tx)"` |
| Tag application | `"Applying tags to {count} transactions..."` |
| Completion | `"Import complete: {imported} imported, {dups} duplicates"` |

### Frontend: Render activity log

Add a collapsible `<div>` below the progress bar that renders the activity log entries:

```html
<div class="card mt-3" x-show="activityLog.length > 0">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Activity log</span>
        <button class="btn btn-sm btn-outline-secondary"
                @click="activityExpanded = !activityExpanded"
                x-text="activityExpanded ? 'Collapse' : 'Expand'">
        </button>
    </div>
    <div class="card-body p-0" x-show="activityExpanded" x-transition>
        <pre class="mb-0 p-2" style="max-height: 300px; overflow-y: auto; font-size: 0.8rem;"
             x-ref="activityPre"><template x-for="entry in activityLog">
<span class="text-muted" x-text="'[' + entry.time + '] '"></span><span x-text="entry.message"></span>
</template></pre>
    </div>
</div>
```

Auto-scroll logic:
```javascript
// In the poll response handler:
if (data.activity_log) {
    this.activityLog = data.activity_log;
    this.$nextTick(() => {
        const pre = this.$refs.activityPre;
        if (pre) pre.scrollTop = pre.scrollHeight;
    });
}
```

## Implementation Steps

### Phase 1: Add activity_log to ConversionStatus

**File:** `app/Services/Shared/Conversion/ConversionStatus.php` (MODIFY)
- Add `$activityLog` array property
- Add `addActivity(string $message)` method with 200-entry cap
- Include `activity_log` in `toArray()`

### Phase 2: Add activity_log to SubmissionStatus

**File:** `app/Services/Shared/Submission/SubmissionStatus.php` (MODIFY)
- Same as Phase 1

### Phase 3: Add activity messages in TRC20 TransactionProcessor

**File:** `app/Services/TRC20/Conversion/Routine/TransactionProcessor.php` (MODIFY)
- Before/after wallet API calls
- During transaction normalization batches
- After completion

### Phase 4: Add activity messages in GetTransactionsRequest

**File:** `app/Services/TRC20/Request/GetTransactionsRequest.php` (MODIFY)
- Log page fetch start/end with row counts
- These need to be surfaced to ConversionStatus — pass a callback or use the importJob's status object

### Phase 5: Add activity messages in ApiSubmitter

**File:** `app/Services/Shared/Submission/ApiSubmitter.php` (MODIFY)
- Before/after each batch submission
- Tag application
- Completion summary

### Phase 6: Render activity log in conversion view (007)

**File:** `resources/views/v2/import/007-convert/index.blade.php` (MODIFY)
- Add activity log card below progress bar
- Collapsible, auto-scroll, monospace font

**File:** `resources/js/v2/src/pages/conversion/index.js` (MODIFY)
- Add `activityLog: []` and `activityExpanded: true` to Alpine data
- Parse `activity_log` from poll response
- Auto-scroll on update

### Phase 7: Render activity log in submission view (008)

**File:** `resources/views/v2/import/008-submit/index.blade.php` (MODIFY)
**File:** `resources/js/v2/src/pages/submit/index.js` (MODIFY)
- Same as Phase 6

## Key Files

| File | Operation | Description |
|------|-----------|-------------|
| `app/Services/Shared/Conversion/ConversionStatus.php` | MODIFY | Add `activityLog` array + `addActivity()` |
| `app/Services/Shared/Submission/SubmissionStatus.php` | MODIFY | Same |
| `app/Services/TRC20/Conversion/Routine/TransactionProcessor.php` | MODIFY | Add activity messages during pull + normalization |
| `app/Services/TRC20/Request/GetTransactionsRequest.php` | MODIFY | Log page fetches |
| `app/Services/Shared/Submission/ApiSubmitter.php` | MODIFY | Add activity messages during batch submission |
| `resources/views/v2/import/007-convert/index.blade.php` | MODIFY | Add activity log UI |
| `resources/views/v2/import/008-submit/index.blade.php` | MODIFY | Add activity log UI |
| `resources/js/v2/src/pages/conversion/index.js` | MODIFY | Parse + render activity log |
| `resources/js/v2/src/pages/submit/index.js` | MODIFY | Parse + render activity log |

## Risks

| Risk | Mitigation |
|------|------------|
| Activity log grows too large in memory | Cap at 200 entries, oldest dropped |
| Frequent disk writes (status saved after each activity) | Batch activities — only save to disk every 5-10 entries |
| Poll response size increases | Activity log is small text — ~50 bytes per entry, 200 max = ~10KB |
| Non-TRC20 providers don't have activity messages | They still work — just show progress bar without log. Add messages to other providers later. |

## Checklist

- [ ] Phase 1: Add `activityLog` to ConversionStatus
- [ ] Phase 2: Add `activityLog` to SubmissionStatus
- [ ] Phase 3: Add activity messages in TRC20 TransactionProcessor
- [ ] Phase 4: Add activity messages in GetTransactionsRequest
- [ ] Phase 5: Add activity messages in ApiSubmitter
- [ ] Phase 6: Render activity log in conversion view (007)
- [ ] Phase 7: Render activity log in submission view (008)
