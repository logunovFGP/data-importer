# Plan: Importer UX — Navigation + Design System Fixes

## Design System Audit Summary

| Dimension | Score | Issue |
|-----------|-------|-------|
| Navigation clarity | 3/10 | Buttons only at bottom, no "go home" without flush, confusing "Start Over" |
| Component consistency | 6/10 | Cards used well, but nav buttons differ per step |
| Information hierarchy | 5/10 | Long pages bury actions, no step indicator |
| Responsiveness | 5/10 | Desktop-first, tables break on mobile |
| Dark mode | 4/10 | Setup exists but inline colors break it |
| Accessibility | 4/10 | ARIA gaps on icon buttons, no focus management |

**Stack:** Bootstrap 5.3.0, Font Awesome 7, Alpine.js, Vite 7, Roboto font.
**Custom colors:** Primary `#1E6581`, Success `#64B624`, Danger `#CD5029`.

## Problems (Ordered by User Impact)

### P1: Navigation (CRITICAL — users get lost)
1. Nav buttons only at page bottom — must scroll on long pages
2. No "go to main page" without flushing session
3. "Start Over" is destructive but doesn't sound like it
4. Each step has its own nav button HTML (no shared partial, 9 copies)

### P2: Step Context (HIGH — users don't know where they are)
5. No step progress indicator (breadcrumb or stepper)
6. No flow name shown on pages (user doesn't know which import type is active)

### P3: Inline Styles Breaking Dark Mode (MEDIUM)
7. `style="color:#e83e8c"` and `style="background-color: #f8f9fa"` don't adapt in dark mode
8. Table column widths use inline `style="width:X%"` instead of CSS classes

### P4: Accessibility Gaps (MEDIUM)
9. Icon-only buttons (pencil, check, X) missing `aria-label`
10. Progress bars missing `aria-valuenow` binding
11. Dynamic content updates missing `aria-live` regions

---

## Implementation Plan

### Phase 1: Create Shared Navigation Partial

**File:** `resources/views/v2/components/step-navigation.blade.php` (NEW)

Parameters:
- `$backUrl` — URL for "Back" button (null = hide)
- `$backLabel` — Back button label (default: "Go back")
- `$identifier` — Import job UUID (null = no download/abort buttons)
- `$flow` — Flow name (e.g., "trc20")
- `$showDownloadConfig` — Show download config button
- `$currentStep` — Current step name (for progress indicator)

Buttons (left to right):
```
[← Back] [Home] [Abort import] [Full reset session] [Download config]
```

```html
@props([
    'backUrl' => null,
    'backLabel' => 'Go back',
    'identifier' => null,
    'flow' => null,
    'showDownloadConfig' => false,
    'currentStep' => null,
])

{{-- Step progress indicator --}}
@if($currentStep)
<nav aria-label="Import progress" class="mb-2">
    <small class="text-muted">
        @if($flow)<strong>{{ ucfirst($flow) }}</strong> &middot; @endif
        {{ $currentStep }}
    </small>
</nav>
@endif

{{-- Navigation buttons --}}
<div class="btn-group btn-group-sm flex-wrap" role="group" aria-label="Navigation">
    @if($backUrl)
    <a href="{{ $backUrl }}" class="btn btn-secondary">
        <span class="fas fa-arrow-left" aria-hidden="true"></span> {{ $backLabel }}
    </a>
    @endif

    <a href="{{ route('index') }}" class="btn btn-outline-primary"
       title="Go to main page without losing your session">
        <span class="fas fa-home" aria-hidden="true"></span> Main page
    </a>

    @if($identifier)
    <a href="{{ route('abort-import', ['identifier' => $identifier]) }}" class="btn btn-outline-warning"
       title="Delete this import job and go to main page"
       onclick="return confirm('This will delete the current import job. Continue?')">
        <span class="fas fa-times-circle" aria-hidden="true"></span> Abort import
    </a>
    @endif

    <a href="{{ route('flush') }}" class="btn btn-danger"
       title="Clear all session data including authentication and start fresh"
       onclick="return confirm('This will clear ALL session data including your Firefly III authentication. Continue?')">
        <span class="fas fa-redo-alt" aria-hidden="true"></span> Full reset session
    </a>

    @if($showDownloadConfig && $identifier)
    <a href="{{ route('configure-import.download', [$identifier]) }}" class="btn btn-info"
       title="Download configuration file for quick re-import next time">
        <span class="fas fa-download" aria-hidden="true"></span> Download config
    </a>
    @endif
</div>
```

Key design decisions:
- `flex-wrap` on btn-group so buttons wrap on mobile instead of overflowing
- `aria-hidden="true"` on icons (text labels are sufficient)
- Confirm dialogs on destructive actions (abort, full reset)
- Step context shown above buttons (flow name + current step)

### Phase 2: Add "Abort Import" Route

**File:** `routes/web.php` (MODIFY)
```php
Route::get('abort-import/{identifier}', [IndexController::class, 'abortImport'])->name('abort-import');
```

**File:** `app/Http/Controllers/IndexController.php` (MODIFY)
```php
public function abortImport(string $identifier): RedirectResponse
{
    $jobPath = storage_path(sprintf('import-jobs/%s.json', $identifier));
    if (file_exists($jobPath)) {
        unlink($jobPath);
    }
    ImportStateManager::clearActiveImport();
    session()->flash('success', 'Import job deleted.');

    return redirect()->route('index');
}
```

### Phase 3: Update All Step Views — Top + Bottom Nav

Replace inline navigation in each view with:
```blade
{{-- TOP navigation --}}
<div class="row mb-3">
    <div class="col-lg-10 offset-lg-1">
        @include('v2.components.step-navigation', [...params...])
    </div>
</div>

{{-- ... page content ... --}}

{{-- BOTTOM navigation --}}
<div class="row mt-3">
    <div class="col-lg-10 offset-lg-1">
        @include('v2.components.step-navigation', [...params...])
    </div>
</div>
```

Per-step configuration:

| View | `$backUrl` | `$backLabel` | `$currentStep` | `$showDownloadConfig` |
|------|-----------|-------------|----------------|---------------------|
| `002-authenticate` | `route('index')` | Go back to index | Authenticate | No |
| `003-upload` | `route('index')` | Go back to index | Upload | No |
| `004-configure` | `route('new-import.index', [$flow])` | Go back to upload | Configure | Yes |
| `005-roles/csv` | `route('configure-import.index', [$id])` | Go back to config | Define roles | No |
| `005-roles/camt` | `route('configure-import.index', [$id])` | Go back to config | Define roles | No |
| `005-roles/no-define` | `route('configure-import.index', [$id])` | Go back to config | Roles (skipped) | No |
| `006-mapping` | `$jobBackUrl` | Go back | Map data | Yes |
| `007-convert` | `$jobBackUrl` | Go back | Convert | Yes |
| `008-submit` | `$jobBackUrl` | Go back | Submit | Yes |

### Phase 4: Update Index Page

**File:** `resources/views/v2/index.blade.php` (MODIFY)
- Rename "Start over" → "Full reset session"
- Add confirm dialog
- Update tooltip to explain what it clears

### Phase 5: Fix Inline Styles for Dark Mode Compatibility

Replace inline color styles with Bootstrap utility classes:

| Current | Replace With | Files |
|---------|-------------|-------|
| `style="color:#e83e8c"` | `class="text-danger-emphasis"` | `005-roles/index-csv.blade.php`, `006-mapping/index.blade.php` |
| `style="background-color: #f8f9fa"` | `class="bg-body-secondary"` | `005-roles/index-csv.blade.php` |
| `style="color:#999"` | `class="text-body-tertiary"` | `005-roles/index-csv.blade.php` |
| `style="width:50%"` on `<th>` | CSS class `.w-50` | `006-mapping/index.blade.php` |

### Phase 6: Accessibility Quick Fixes

Add `aria-label` to icon-only buttons in `create-account-widget.blade.php`:
```html
<!-- Before -->
<button class="btn btn-sm btn-outline-secondary"><i class="fas fa-pencil"></i></button>

<!-- After -->
<button class="btn btn-sm btn-outline-secondary" aria-label="Edit account name">
    <i class="fas fa-pencil" aria-hidden="true"></i>
</button>
```

Apply to all icon-only buttons: edit (pencil), save (check), cancel (times).

## Key Files

| File | Operation | Description |
|------|-----------|-------------|
| `resources/views/v2/components/step-navigation.blade.php` | NEW | Shared navigation partial with step context |
| `routes/web.php` | MODIFY | Add `abort-import/{identifier}` route |
| `app/Http/Controllers/IndexController.php` | MODIFY | Add `abortImport()` method |
| `resources/views/v2/index.blade.php` | MODIFY | Rename "Start over" → "Full reset session" |
| `resources/views/v2/import/002-authenticate/index.blade.php` | MODIFY | Replace inline nav → shared partial (top + bottom) |
| `resources/views/v2/import/003-upload/index.blade.php` | MODIFY | Same |
| `resources/views/v2/import/004-configure/index.blade.php` | MODIFY | Same |
| `resources/views/v2/import/005-roles/index-csv.blade.php` | MODIFY | Same + fix inline styles |
| `resources/views/v2/import/005-roles/index-camt.blade.php` | MODIFY | Same |
| `resources/views/v2/import/005-roles/no-define-roles.blade.php` | MODIFY | Same |
| `resources/views/v2/import/006-mapping/index.blade.php` | MODIFY | Same + fix inline styles |
| `resources/views/v2/import/007-convert/index.blade.php` | MODIFY | Same |
| `resources/views/v2/import/008-submit/index.blade.php` | MODIFY | Same |
| `resources/views/v2/components/create-account-widget.blade.php` | MODIFY | Add aria-labels |
| `resources/js/v2/src/sass/app.scss` | MODIFY | Add utility classes if needed |

## Risks

| Risk | Mitigation |
|------|------------|
| Breaking existing nav for non-TRC20 flows | Shared partial uses same routes, just organized |
| "Abort" deleting wrong job | UUID validation + file existence check + confirm dialog |
| Dark mode replacements look different | Use Bootstrap 5.3 semantic color classes which auto-adapt |
| Secret provider flush (basisbank/tbank use form POST) | The `flush` route handler already clears provider-specific credentials |

## Checklist

- [ ] Phase 1: Create `step-navigation.blade.php` shared partial with step context
- [ ] Phase 2: Add `abort-import/{identifier}` route + controller method
- [ ] Phase 3: Update all 9 step views with top + bottom shared navigation
- [ ] Phase 4: Rename "Start over" → "Full reset session" on index page
- [ ] Phase 5: Replace inline color styles with Bootstrap semantic classes
- [ ] Phase 6: Add aria-labels to icon-only buttons
- [ ] Verify: Test all flows (file, TRC20, BasisBank) with new navigation
