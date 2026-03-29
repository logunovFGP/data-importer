# Plan: Standardized Import Flow — Unified 4-Step Navigation

## Problem

The current import flow has inconsistent steps depending on the provider:
- File flows: Upload → Configure → Roles → Mapping → Convert → Submit (6 steps)
- API flows: Authenticate → Upload → Configure → [Bank Selection] → Convert → [Mapping] → Submit (4-7 steps)
- Account creation is mixed into the Convert page alongside progress bars
- Transaction board, activity log, and account forms all share the same page
- No clear, predictable flow the user can learn

## Target Architecture: 4 Standardized Steps

Every import follows the same 4-step structure, regardless of provider:

```
Step 1: AUTH           Step 2: ACCOUNTS       Step 3: MAP            Step 4: IMPORT
┌──────────────┐      ┌──────────────┐      ┌──────────────┐      ┌──────────────┐
│ Provider-     │      │ Parse wallet │      │ Map source   │      │ Live import  │
│ specific auth │ ──►  │ / fetch accts│ ──►  │ accounts to  │ ──►  │ status board │
│ + credentials │      │ + show list  │      │ FF3 accounts │      │ + tx board   │
│              │      │              │      │              │      │ + activity   │
│ (Custom per  │      │ (Generic:    │      │ (Generic:    │      │ (Generic:    │
│  provider)   │      │  account     │      │  account     │      │  progress +  │
│              │      │  discovery)  │      │  mapping)    │      │  board)      │
└──────────────┘      └──────────────┘      └──────────────┘      └──────────────┘
```

### Step 1: AUTH (provider-specific)
- **File**: Upload CSV/CAMT + optional config file
- **TRC20**: API key + wallet addresses
- **BasisBank**: Login + OTP + trust device
- **TBank**: OAuth callback
- **Nordigen**: Bank selection + OAuth
- **SimpleFIN**: Setup token exchange
- **Current pages**: 002-authenticate + 003-upload (merged)
- **Output**: Credentials stored, ready to discover accounts

### Step 2: ACCOUNTS (generic)
- Parse/fetch accounts from the provider
- Display discovered accounts as a list
- **New accounts to create** forms appear HERE (not on convert page)
- Currency preflight validation runs here
- **Current pages**: 004-configure + account creation from 007-convert (merged)
- **Output**: Accounts discovered, configured, and ready to map

### Step 3: MAP (generic)
- Map source accounts → Firefly III asset accounts
- Map categories, opposing accounts (for file flows: also column roles)
- Duplicate detection settings
- Import options (tags, rules, date range)
- **Current pages**: 004-configure (mapping parts) + 005-roles + 006-mapping (merged)
- **Output**: Full import configuration ready

### Step 4: IMPORT (generic, per-import instance)
- Start conversion + submission in one flow
- **Live status board** (BasisBank-style pull checklist)
- **Transaction board** (real-time per-transaction status)
- **Activity log** (timestamped messages)
- **No account forms** — all account setup is done in Steps 2-3
- Progress: fetch → normalize → submit to Firefly III
- Parallel imports: each import has its own instance of this page
- **Current pages**: 007-convert + 008-submit (merged into single page)
- **Output**: Transactions imported into Firefly III

## Step Navigation Bar

A persistent step indicator at the top of every page:

```html
<nav class="step-indicator mb-3">
  <ol class="d-flex list-unstyled justify-content-between">
    <li class="step completed">1. Auth</li>
    <li class="step active">2. Accounts</li>
    <li class="step">3. Map</li>
    <li class="step">4. Import</li>
  </ol>
</nav>
```

States: `completed` (green check) → `active` (blue, bold) → `pending` (gray)

## Implementation Phases

### Phase 1: Quick Fix — Reorder Convert Page (do NOW)

Move transaction board + activity log ABOVE the "New accounts to create" section in `007-convert/index.blade.php`. This is a 1-minute fix that immediately improves the layout while the larger refactor is planned.

**File:** `resources/views/v2/import/007-convert/index.blade.php`
- Move lines 269-291 (tx board + activity log) to before line 188 (accounts section)

### Phase 2: Extract Step Indicator Component

**File:** `resources/views/v2/components/step-indicator.blade.php` (NEW)

```blade
@php
    $currentStep = $currentStep ?? 1;
    $steps = ['Auth', 'Accounts', 'Map', 'Import'];
@endphp
<nav aria-label="Import steps" class="mb-3">
    <ol class="d-flex list-unstyled justify-content-between mb-0 px-0">
        @foreach($steps as $index => $label)
            @php $stepNum = $index + 1; @endphp
            <li class="text-center flex-fill {{ $stepNum < $currentStep ? 'text-success' : ($stepNum === $currentStep ? 'fw-bold text-primary' : 'text-muted') }}">
                @if($stepNum < $currentStep)
                    <span class="fas fa-check-circle"></span>
                @elseif($stepNum === $currentStep)
                    <span class="fas fa-circle"></span>
                @else
                    <span class="far fa-circle"></span>
                @endif
                {{ $stepNum }}. {{ $label }}
            </li>
        @endforeach
    </ol>
</nav>
```

Include on every import step page above the step-navigation buttons.

### Phase 3: Move Account Creation to Configure Step (Step 2)

**Current**: Account creation forms are on the Convert page (007-convert).
**Target**: Move to Configure page (004-configure), after the account list.

This means:
- `004-configure/index.blade.php` gets the "New accounts to create" card
- `007-convert/index.blade.php` loses the account forms — becomes pure progress/status
- `ConversionController::start()` no longer receives `new_account_data` POST data
- Account creation runs during `ConfigurationController::postIndex()` instead

### Phase 4: Merge Convert + Submit into Single "Import" Page (Step 4)

**Current**: Convert (007) and Submit (008) are separate pages with separate polling.
**Target**: Single "Import" page that runs conversion → submission sequentially.

The new Import page:
1. Shows "Starting import..." → runs conversion
2. Shows pull checklist + transaction board during conversion
3. Auto-transitions to submission when conversion completes
4. Shows submission progress + performance table
5. Shows completion summary with "View in Firefly III" link

This requires:
- New `ImportController` (or modify `ConversionController`) to handle both phases
- Single Alpine.js component that manages both `conv_*` and `submission_*` states
- No page redirect between conversion and submission

### Phase 5: Parallel Import Support

Each import creates a unique job with its own status endpoint. The index page shows active imports with "View progress" links. Multiple imports can run simultaneously (each on their own Import page instance).

**Already supported**: Import jobs have unique UUIDs and independent status files. The infrastructure exists — just needs UI to show multiple active imports.

## Key Files

| File | Operation | Phase | Description |
|------|-----------|-------|-------------|
| `007-convert/index.blade.php` | MODIFY | 1 | Move tx board above accounts section |
| `components/step-indicator.blade.php` | NEW | 2 | Shared step indicator component |
| `004-configure/index.blade.php` | MODIFY | 3 | Add account creation forms |
| `007-convert/index.blade.php` | MODIFY | 3 | Remove account creation forms |
| `ConversionController.php` | MODIFY | 3 | Remove `new_account_data` handling |
| `ConfigurationController.php` | MODIFY | 3 | Add account creation handling |
| All step views | MODIFY | 2 | Include step-indicator component |

## Scope

| Phase | Effort | Impact | Priority |
|-------|--------|--------|----------|
| Phase 1: Reorder convert page | 5 min | HIGH — fixes immediate layout bug | NOW |
| Phase 2: Step indicator component | 30 min | MEDIUM — visual navigation clarity | Next |
| Phase 3: Move account creation | 2-3 hrs | HIGH — clean separation of concerns | Next |
| Phase 4: Merge convert + submit | 4-6 hrs | HIGH — simplified flow, no page redirect | Later |
| Phase 5: Parallel import UI | 2-3 hrs | MEDIUM — multi-import visibility | Later |

## Risks

| Risk | Mitigation |
|------|------------|
| Breaking file flows (CSV/CAMT have different step order) | File flows keep roles/mapping steps; step indicator adapts labels |
| Account creation timing (configure vs convert) | Accounts must be created before conversion starts — validate in configure |
| Merge convert+submit breaks polling | Single page handles both status types with state machine |
| Existing import jobs mid-flow | Old jobs continue with old page structure; new jobs use new flow |

---

## Architect Review (2026-03-29)

### CRITICAL Issues Found

**C1: Account creation cannot move to Configure step.**
Account creation runs inside the conversion routine (`CreatesAccounts` trait) which needs bank API data fetched at conversion time. The `createOrFindExistingAccount()` call depends on `$this->existingServiceAccounts` which are populated by the conversion routine, not the controller. Moving the **form** earlier is fine; moving the **execution** breaks the data flow.

**C2: Column roles (step 005) ≠ mapping (step 006).**
The plan merges these into a single "Map" step, but they are fundamentally incompatible:
- Roles must happen BEFORE conversion (they configure how CSV columns are parsed)
- Mapping happens AFTER conversion for API providers (opposing accounts come from converted transactions)
- `conversion_before_mapping = true` (all API providers) vs `false` (file only) creates two step orderings

**C3: Two fundamentally different flow shapes cannot be forced into a fixed 4-step model.**
- File: Configure → Roles → Mapping → Convert → Submit
- API: Configure → Convert → [Mapping] → Submit

A fixed 4-step indicator misrepresents the flow for one or both provider types.

### Revised Architecture: Flow-Aware Step Model

Replace the forced 4-step model:

```
File flow:     Auth → Configure → Roles → Map → Import
API flow:      Auth → Configure → Import (convert+submit combined)
```

The step indicator component must accept a dynamic list of steps per flow, not a hardcoded array.

### Phase 3 Amendment

**Original**: Move account creation from convert to configure step.
**Revised**: Keep account creation execution in the conversion routine. Only improve the UX by reordering the convert page so account forms appear FIRST (before "Start job" button), and progress/board/activity appear AFTER.

### Phase 4 Amendment

Split into two sub-phases:
- **4a**: Auto-redirect from conversion completion to submission (no manual page navigation)
- **4b**: Merge into single page with unified Alpine.js component handling both sync conversion + async submission polling

### Phase 5 Amendment

**BLOCKED** until session isolation is addressed:
- `ImportStateManager` tracks only 1 active import per session
- `SecretManager` auth tokens are session-scoped, shared across all jobs
- Need per-job auth context before parallel imports can work

### Dependency Graph

```
Phase 1 (reorder convert page)     ← SAFE, do now
    ↓
Phase 2 (step indicator)           ← SAFE, independent
    ↓
Phase 3 (improve account form UX)  ← NEEDS ConfigurationPostRequest update
    ↓
Phase 4a (auto-redirect submit)    ← Light, depends on Phase 1
Phase 4b (merge pages)             ← Heavy, depends on Phase 3
    ↓
Phase 5 (parallel imports)         ← BLOCKED by session isolation
```

### Missing Items Identified

1. **State machine update**: `ImportStateManager::STEP_ORDER` and `ImportStepGuard` must be updated for any new step structure
2. **GoCardless bank-selection sub-flow**: Not addressed — happens mid-flow between Configure and Convert
3. **`skipForm` config option**: Auto-skip configure step when saved config uploaded — not addressed
4. **Auto-import / CLI compatibility**: `AutoImportController` uses same pipeline — structural changes must be verified
5. **JavaScript asset merge**: Merging convert+submit pages (Phase 4b) requires merging Alpine.js components

---

---

## UI/UX Design System — Conversion Page Rework

### Visual Audit (Current State)

| Dimension | Score | Issue |
|-----------|-------|-------|
| Information hierarchy | 3/10 | Account forms sit between progress and transaction data — breaks reading flow |
| Component consistency | 5/10 | 3 different card styles on one page (standard, border-warning, no-border activity log) |
| Information density | 4/10 | Account forms take 60% of vertical space; transaction board cramped at bottom |
| Dark mode | 7/10 | Mostly works via Bootstrap semantic classes; `<pre>` bg uses `var(--bs-body-bg)` correctly |
| Responsive | 5/10 | `col-md-6` split in account forms doesn't stack gracefully on mobile |
| Polish | 4/10 | No loading skeletons, no transition between states, abrupt section appearance |

### Target Layout — Phase 1 (Reorder)

```
┌──────────────────────────────────────────────────────────────┐
│ h1: Convert the data                                         │
│ [← Back] [Main page] [Abort] [Full reset] [Download config]  │
├──────────────────────────────────────────────────────────────┤
│ CARD: Data conversion                                        │
│   ┌────────────────────────────────────────────────────────┐ │
│   │ [Start job →]  OR  progress bars + pull checklist      │ │
│   │ OR error state  OR done state                          │ │
│   └────────────────────────────────────────────────────────┘ │
├──────────────────────────────────────────────────────────────┤
│ CARD: Transaction board [193 total]            [Collapse ▼]  │
│   ┌────────────────────────────────────────────────────────┐ │
│   │ ... and 93 more transactions processed                 │ │
│   │ ✅ ea87.. │ 1,090.98 USDT │ ← │ TDjet..Kpx │ 12-15  │ │
│   │ ✅ 7195.. │    11.40 USDT │ ← │ TDjet..Kpx │ 12-14  │ │
│   │ ...                                                    │ │
│   └────────────────────────────────────────────────────────┘ │
├──────────────────────────────────────────────────────────────┤
│ CARD: Activity log                             [Collapse ▼]  │
│   ┌────────────────────────────────────────────────────────┐ │
│   │ [01:34:08] Wallet TT65LR..: Fetching page 1...        │ │
│   │ [01:34:09] Wallet TT65LR..: Received 200 rows...      │ │
│   └────────────────────────────────────────────────────────┘ │
├──────────────────────────────────────────────────────────────┤
│ CARD: New accounts to create (border-warning)                │
│   (only if supports_new_accounts && newAccountsToCreate > 0) │
│   ┌────────────────────────────────────────────────────────┐ │
│   │ Account forms (name, type, currency, balance)          │ │
│   └────────────────────────────────────────────────────────┘ │
├──────────────────────────────────────────────────────────────┤
│ [← Back] [Main page] [Abort] [Full reset] [Download config]  │
└──────────────────────────────────────────────────────────────┘
```

**Rationale**: The conversion progress + transaction board + activity log form a coherent "what's happening" group. Account creation forms are a "setup before you start" concern — they belong below the monitoring section because the user interacts with them once (before clicking "Start job") and then watches the progress section. Moving them to the bottom avoids splitting the live monitoring view.

### Component Style Guide

**Consistent card styling** — all cards on the conversion page should use the same pattern:

```html
<!-- Standard card (progress, transaction board, activity log) -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Title <span class="badge bg-secondary ms-1">N total</span></span>
        <button class="btn btn-sm btn-outline-secondary" ...>Collapse</button>
    </div>
    <div class="card-body">...</div>
</div>

<!-- Warning card (account creation — user action required) -->
<div class="card border-warning">
    <div class="card-header bg-warning bg-opacity-10">
        <span class="fas fa-plus-circle me-1"></span> Title
    </div>
    <div class="card-body">...</div>
</div>
```

**Status icon palette** (Font Awesome 7 + Bootstrap semantic colors):

| State | Icon | Color Class | Usage |
|-------|------|-------------|-------|
| Fetched | `fa-download` | `text-info` | Transaction fetched from API |
| Converting | `fa-cog fa-spin` | `text-primary` | Processing in progress |
| Submitted | `fa-check-circle` | `text-success` | Successfully sent to Firefly III |
| Duplicate | `fa-forward` | `text-warning` | Skipped as duplicate |
| Error | `fa-times-circle` | `text-danger` | Failed |
| Pending | `fa-clock` | `text-muted` | Waiting in queue |
| Done (account) | `bg-success` badge | White text | Pull checklist done |
| Running (account) | `bg-primary` badge | White text | Pull checklist active |

**Typography for data displays**:

```css
/* Transaction board + activity log — compact monospace */
.importer-data-table { font-size: 0.8rem; }
.importer-data-table code { font-size: 0.75rem; }
.importer-activity-pre {
    font-size: 0.8rem;
    max-height: 250px;
    overflow-y: auto;
    background: var(--bs-body-bg); /* dark mode safe */
}
```

### Step Indicator Component Design

**Flow-aware** — accepts dynamic step list per provider flow:

```blade
@php
    $steps = match($flow) {
        'file'  => ['Auth', 'Configure', 'Roles', 'Map', 'Import'],
        default => ['Auth', 'Configure', 'Import'],
    };
    $currentStepNum = $currentStepNum ?? 1;
@endphp
<nav aria-label="Import steps" class="mb-3">
    <div class="d-flex justify-content-between align-items-center px-2">
        @foreach($steps as $i => $label)
            @php $num = $i + 1; @endphp
            <div class="text-center flex-fill position-relative">
                {{-- Connector line --}}
                @if($i > 0)
                <div class="position-absolute top-50 start-0 translate-middle-y"
                     style="width: 50%; height: 2px; left: 0; z-index: 0;"
                     class="{{ $num <= $currentStepNum ? 'bg-primary' : 'bg-secondary bg-opacity-25' }}"></div>
                @endif
                @if($i < count($steps) - 1)
                <div class="position-absolute top-50 end-0 translate-middle-y"
                     style="width: 50%; height: 2px; right: 0; z-index: 0;"
                     class="{{ $num < $currentStepNum ? 'bg-primary' : 'bg-secondary bg-opacity-25' }}"></div>
                @endif
                {{-- Step circle --}}
                <div class="position-relative d-inline-block" style="z-index: 1;">
                    @if($num < $currentStepNum)
                        <span class="badge rounded-pill bg-success" style="width: 28px; height: 28px; line-height: 20px;">
                            <span class="fas fa-check" style="font-size: 0.7rem;"></span>
                        </span>
                    @elseif($num === $currentStepNum)
                        <span class="badge rounded-pill bg-primary" style="width: 28px; height: 28px; line-height: 20px;">
                            {{ $num }}
                        </span>
                    @else
                        <span class="badge rounded-pill bg-secondary bg-opacity-25 text-muted" style="width: 28px; height: 28px; line-height: 20px;">
                            {{ $num }}
                        </span>
                    @endif
                </div>
                <div class="mt-1">
                    <small class="{{ $num === $currentStepNum ? 'fw-bold text-primary' : ($num < $currentStepNum ? 'text-success' : 'text-muted') }}">
                        {{ $label }}
                    </small>
                </div>
            </div>
        @endforeach
    </div>
</nav>
```

**Visual**:
```
  ✅──────●──────○──────○
 Auth   Configure  Import  Submit
          ▲ (active, blue, bold)
```

### CSS Additions to `app.scss`

Add to the end of `app.scss` (before Bootstrap import stays where it is):

```scss
/* Importer-specific utilities */
.importer-data-table {
    font-size: 0.8rem;

    code {
        font-size: 0.75rem;
        color: var(--bs-body-color);
        background: none;
    }

    th {
        font-weight: 500;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }
}

.importer-activity-pre {
    font-size: 0.8rem;
    max-height: 250px;
    overflow-y: auto;
    background: var(--bs-body-bg);
    margin-bottom: 0;
    padding: 0.5rem;
}

.importer-step-indicator {
    .step-connector {
        height: 2px;
        position: absolute;
        top: 50%;
        transform: translateY(-50%);
        z-index: 0;
    }
}
```

### Deprecated Patterns to Remove

| Pattern | Location | Replace With |
|---------|----------|-------------|
| Inline `style="max-height: 250px; overflow-y: auto; font-size: 0.8rem;"` | activity log `<pre>` | `.importer-activity-pre` class |
| Inline `style="font-size: 0.8rem;"` | transaction board `<table>` | `.importer-data-table` class |
| Inline `style="font-size: 0.75rem;"` | `<code>` in board | Inherited from `.importer-data-table code` |
| `@props([...])` in `@include` partials | step-navigation, transaction-board | `@php ... @endphp` variable defaults |
| `style="height: 8px;"` | nested progress bars | CSS class `.progress-sm { height: 8px; }` |

### Dark Mode Verification Checklist

All colors must use Bootstrap semantic variables, not hex values:

- [x] `var(--bs-body-bg)` for activity log background
- [x] `bg-body-secondary` for overflow banner
- [x] `text-muted`, `text-primary`, `text-success`, `text-danger`, `text-warning` for status icons
- [x] `bg-success`, `bg-primary`, `bg-secondary` for badges
- [ ] **TODO**: Replace `border-warning` with `border-warning-subtle` for softer dark mode appearance
- [ ] **TODO**: Add `bg-warning bg-opacity-10` to account card header for subtle emphasis in dark mode

---

## Checklist (Revised)

- [ ] Phase 1: Reorder convert page — tx board + activity log above accounts
- [ ] Phase 1b: Add `.importer-data-table` and `.importer-activity-pre` CSS classes
- [ ] Phase 1c: Replace inline styles with CSS classes in transaction-board + activity log
- [ ] Phase 2: Create flow-aware step-indicator component, include on all pages
- [ ] Phase 3: Improve account form UX on convert page (keep execution in conversion routine)
- [ ] Phase 4a: Auto-redirect from conversion done → submission start
- [ ] Phase 4b: Merge convert + submit into single Import page (unified Alpine.js)
- [ ] Phase 5: Parallel import UI (BLOCKED — needs session isolation first)

---

## Parallel Execution Strategy

### Agent Assignment Matrix

Phases are grouped into **parallel tracks** based on file dependencies. Two agents can work simultaneously when their file sets don't overlap.

```
TIME ─────────────────────────────────────────────────────────►

Track A (Frontend/Blade):    ┌─Phase 1─┐  ┌─Phase 2──┐  ┌Phase 1b/1c┐
                             │ Reorder │  │Step indic.│  │CSS classes │
                             │ convert │  │component  │  │+ cleanup   │
                             └─────────┘  └──────────┘  └───────────┘
                                          ▲ parallel     ▲ parallel

Track B (Backend/PHP):                    ┌Phase 3 prep─┐
                                          │Account form │
                                          │UX + dark    │
                                          │mode borders │
                                          └────────────┘

Track C (JS/Alpine):                                     ┌─Phase 4a──┐
                                                         │Auto-redir.│
                                                         │conv→submit│
                                                         └───────────┘

                             ┌─── GATE: Vite rebuild + E2E verify ───┐
                             └───────────────────────────────────────┘

                                                          ┌Phase 4b──┐
                                                          │Merge     │
                                                          │pages     │
                                                          └──────────┘
```

### Agent Assignments

| Phase | Agent Type | Files Touched | Can Parallel With |
|-------|-----------|---------------|-------------------|
| **1** | `dev-frontend` | `007-convert/index.blade.php` | Nothing (first) |
| **1b** | `dev-frontend-tailwind` | `app.scss` | Phase 2 |
| **1c** | `dev-frontend` | `transaction-board.blade.php`, `007-convert`, `008-submit` | Phase 2 |
| **2** | `dev-frontend` | `step-indicator.blade.php` (NEW), all step views | Phase 1b |
| **3** | `dev-backend-engineer` | `007-convert/index.blade.php` (account forms), dark mode CSS | Phase 2 (different files) |
| **4a** | `dev-backend-engineer` | `conversion/index.js`, `ConversionController.php` | — |
| **4b** | `dev-frontend` + `dev-backend-engineer` | Many files (JS merge, routes, controllers) | — |

### Parallel Execution Groups

**Group 1 (do first, sequential)**:
```
Phase 1 → dev-frontend agent
  File: 007-convert/index.blade.php
  Task: Move tx board + activity log blocks above accounts section
```

**Group 2 (parallel after Group 1)**:
```
┌─ Phase 1b → dev-frontend-tailwind agent
│    File: app.scss
│    Task: Add .importer-data-table, .importer-activity-pre, .progress-sm classes
│
├─ Phase 1c → dev-frontend agent
│    Files: transaction-board.blade.php, 007-convert, 008-submit
│    Task: Replace inline styles with CSS classes
│
└─ Phase 2 → dev-frontend agent (separate worktree)
     Files: step-indicator.blade.php (NEW), all step views
     Task: Create flow-aware step indicator, include on pages
```

**Group 3 (after Group 2 merges)**:
```
Phase 3 → dev-backend-engineer agent
  Files: 007-convert/index.blade.php (account section), app.scss (dark mode)
  Task: Reorder account forms before Start button, border-warning-subtle
```

**Group 4 (after Group 3)**:
```
Phase 4a → dev-backend-engineer agent
  Files: conversion/index.js, ConversionController.php
  Task: Auto-redirect from conv_done → submit page
```

**Gate: Vite rebuild + full E2E verify**

**Group 5 (after Gate passes)**:
```
Phase 4b → dev-frontend + dev-backend-engineer (sequential, same worktree)
  Files: Many (JS merge, route changes, controller merge)
  Task: Merge convert + submit into single Import page
```

### Worktree Strategy

For Groups 2+: Use `isolation: "worktree"` on agents to avoid file conflicts.

```
main branch ─── Phase 1 committed ─── merge Group 2 ─── merge Group 3 ─── ...
                    │
                    ├── worktree: phase-1b (app.scss only)
                    ├── worktree: phase-1c (blade templates)
                    └── worktree: phase-2 (step indicator)
```

---

## Verify → Fix → Verify Loop (Final Stage)

After ALL phases are implemented and committed, run this automated verification loop:

### Loop Structure

```
┌──────────────────────────────────────────────────┐
│                 VERIFY STAGE                      │
│                                                  │
│  1. Rebuild Vite assets                          │
│  2. Restart Docker container                     │
│  3. Clear view cache                             │
│  4. Run E2E: full TRC20 import flow              │
│     (auth → upload → configure → convert)        │
│  5. Capture screenshots at each step             │
│  6. Check container logs for errors              │
│  7. Check browser console for Alpine.js errors   │
│  8. Verify transaction board renders data         │
│  9. Verify activity log shows messages            │
│ 10. Verify step indicator shows correct step      │
│                                                  │
│  Result: PASS → Done  /  FAIL → Fix Stage        │
└──────────────────────────────────────────────────┘
           │ FAIL
           ▼
┌──────────────────────────────────────────────────┐
│                  FIX STAGE                        │
│                                                  │
│  1. Parse error from container logs              │
│  2. Parse Alpine.js console errors               │
│  3. Identify root cause file + line              │
│  4. Apply targeted fix (minimal diff)            │
│  5. Do NOT restructure — only fix the bug        │
│                                                  │
│  Output: Fix applied → Re-enter Verify Stage     │
└──────────────────────────────────────────────────┘
           │
           ▼
┌──────────────────────────────────────────────────┐
│              RE-VERIFY STAGE                      │
│                                                  │
│  Same as Verify Stage, but with:                 │
│  - Max 3 iterations (prevent infinite loop)      │
│  - Each iteration captures diff of what changed  │
│  - If 3 iterations fail → STOP, report to user   │
│                                                  │
│  Result: PASS → Commit + Push                    │
│          FAIL (3x) → Manual intervention needed  │
└──────────────────────────────────────────────────┘
```

### Agent Assignment for Loop

| Stage | Agent | Task |
|-------|-------|------|
| Verify | `e2e-runner` or `qa-ui-tester` | Run Playwright E2E, capture screenshots, check console |
| Log analysis | `dev-debugger-ultrathink` | Parse container logs, identify root cause |
| Fix | `dev-backend-engineer` or `dev-frontend` | Apply targeted fix based on diagnosis |
| Re-verify | `e2e-runner` | Re-run same E2E suite |

### E2E Verification Script (Playwright)

```javascript
// verify-conversion-page.js
async (page) => {
  const checks = [];

  // Auth
  await page.context().clearCookies();
  await page.goto('http://localhost:9998/');
  // ... OAuth flow with saved credentials ...

  // Upload
  await page.goto('http://localhost:9998/new-import/trc20');
  await page.getByRole('button', { name: 'Next →' }).click();
  checks.push({ name: 'Upload → Configure', pass: page.url().includes('configure-import') });

  // Configure (wait for parser)
  await page.waitForTimeout(20000);
  checks.push({ name: 'Parser complete', pass: !page.url().includes('new-import') });

  // Step indicator visible
  const stepIndicator = await page.locator('.importer-step-indicator').count();
  checks.push({ name: 'Step indicator', pass: stepIndicator > 0 });

  // Submit → Convert
  await page.getByRole('button', { name: 'Submit →' }).click();
  await page.waitForURL('**/data-conversion/**');

  // Start job
  const startBtn = page.getByRole('button', { name: /Start job/ });
  if (await startBtn.isVisible()) await startBtn.click();

  // Wait for conversion
  for (let i = 0; i < 15; i++) {
    await page.waitForTimeout(5000);
    const done = await page.locator('text=finished').count();
    if (done > 0) break;
  }

  // Verify components
  const txBoard = await page.locator('text=Transaction board').count();
  checks.push({ name: 'Transaction board visible', pass: txBoard > 0 });

  const boardRows = await page.locator('.importer-data-table tbody tr').count();
  checks.push({ name: 'Board has data rows', pass: boardRows > 0 });

  const activityLog = await page.locator('text=Activity log').count();
  checks.push({ name: 'Activity log visible', pass: activityLog > 0 });

  const alpineErrors = await page.evaluate(() =>
    performance.getEntriesByType('resource').filter(e => e.name.includes('error')).length
  );
  checks.push({ name: 'No Alpine errors', pass: alpineErrors === 0 });

  // Layout order: tx board BEFORE accounts
  const boardY = await page.locator('text=Transaction board').first().boundingBox().then(b => b?.y ?? 999);
  const accountsY = await page.locator('text=New accounts').first().boundingBox().then(b => b?.y ?? 0).catch(() => 999);
  checks.push({ name: 'Board above accounts', pass: boardY < accountsY });

  // Screenshot
  await page.screenshot({ path: 'verify-final.png', fullPage: true });

  return checks;
};
```

### Stop Conditions

| Condition | Action |
|-----------|--------|
| All checks PASS | Commit, push, report success |
| Fix iteration 1 FAIL | Apply fix, re-verify |
| Fix iteration 2 FAIL | Apply fix, re-verify |
| Fix iteration 3 FAIL | STOP — report all failures to user |
| Container won't start | STOP — report Docker error |
| Vite build fails | STOP — report build error |
