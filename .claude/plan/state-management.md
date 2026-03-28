# Plan: State Caching and Navigation State Management

## Problem

The data-importer has three disconnected state layers (Firefly III auth, import job on disk, session-based flow context) with no unified navigation controller. This causes:

1. **Session loss on container restart** — provider credentials (BasisBank session artifact, TBank session, TRC20 keys) are in PHP session only, lost on restart
2. **No step enforcement** — users can navigate directly to any URL (e.g., `/submit-data/{id}` without completing conversion) and hit raw `exit()` calls or broken states
3. **"Start over" is destructive** — flushes entire session including Firefly III auth, but doesn't delete the import job from disk
4. **No "resume import" capability** — after restart or session loss, users can't resume an in-progress import
5. **Back navigation inconsistent** — some steps have back links, some don't, some point to wrong steps

## Current Architecture

```
Layer 1: Firefly III Auth (session + config)
  └── SecretManager → session()->get('session_token')

Layer 2: Import Job (disk: storage/import-jobs/{UUID}.json)
  └── ImportJobRepository → file_get_contents('{UUID}.json')

Layer 3: Provider Auth (session only)
  └── BasisBank SecretManager → session()->get('basisbank_*')
  └── TBank SecretManager → session()->get('tbank_*')
```

**Gap:** Layer 3 is session-only — lost on restart. Layer 2 is disk-only — no session tracking of "current job". No middleware enforces step ordering.

## Architecture: ImportStateManager (new unified layer)

### Concept

A single `ImportStateManager` service that:
1. Tracks the current import job identifier in the session
2. Persists provider auth state alongside the import job (not just in session)
3. Provides step validation (can't skip to submit without conversion)
4. Enables "resume import" after session loss

### Implementation Steps

### Phase 1: Create ImportStateManager

**File:** `app/Services/Shared/State/ImportStateManager.php` (NEW)

```php
class ImportStateManager
{
    // Track current active import in session
    public static function setActiveImport(string $identifier, string $flow): void
    public static function getActiveImport(): ?array  // {identifier, flow}
    public static function clearActiveImport(): void

    // Step validation
    public static function canAccessStep(string $step, ImportJob $job): bool
    public static function getCurrentStep(ImportJob $job): string
    public static function getNextStep(ImportJob $job): string
    public static function getPreviousStep(ImportJob $job): string

    // Provider auth persistence (save to job JSON, not just session)
    public static function saveProviderAuth(string $identifier, array $authData): void
    public static function loadProviderAuth(string $identifier): array
}
```

Step validation rules:
```
authenticate → always accessible
upload → always accessible (creates new job)
configure → requires job state >= contains_content
roles → requires job state >= is_configured (file flows only)
mapping → requires job state >= configured_and_roles_defined
convert → requires job state >= configured_and_roles_defined
submit → requires job state >= ready_for_submission
```

### Phase 2: Add NavigationMiddleware

**File:** `app/Http/Middleware/ImportStepGuard.php` (NEW)

Middleware that:
1. Extracts `{identifier}` from the route
2. Loads the import job
3. Checks if the current step is valid for the job's state
4. If invalid → redirects to the correct step with a flash message
5. Sets `$request->attributes->set('importJob', $job)` for the controller

Register on all import step routes (`configure-import`, `configure-roles`, `data-mapping`, `data-conversion`, `submit-data`).

### Phase 3: Persist Provider Auth to Import Job

Currently: BasisBank session artifact, TBank session, TRC20 keys are in PHP session only.

Change: When saving provider auth via SecretManager, ALSO write to the import job JSON under `authentication_details`:

```json
{
  "authentication_details": {
    "provider": "basisbank",
    "session_artifact": "base64...",
    "auth_state": "AUTHENTICATED",
    "login": "encrypted...",
    "trust_device": true
  }
}
```

This enables resuming after session loss — when the middleware loads the job, it can restore provider auth from the job JSON into the session.

### Phase 4: Add "Resume Import" to Index Page

**File:** `app/Http/Controllers/IndexController.php` (MODIFY)

In `index()`, scan `storage/import-jobs/` for recent jobs (last 24 hours) that are not in terminal state (`ready_for_submission` or earlier). Show a "Resume import" section on the landing page:

```html
<div class="card">
    <div class="card-header">Recent imports</div>
    <div class="card-body">
        <table>
            <tr><td>BasisBank import</td><td>Step: Configure</td><td><a href="/configure-import/{id}">Resume</a></td></tr>
        </table>
    </div>
</div>
```

### Phase 5: Fix "Start Over" to Be Less Destructive

Change `IndexController::flush()`:
1. Don't flush the ENTIRE session — only clear import-related keys
2. Preserve Firefly III auth (base URL, access token)
3. Delete the current active import job from disk (if any)
4. Redirect to index

### Phase 6: Add "Download Configuration" to All Steps

Currently only available on the submit page. Add to configure, convert, and mapping steps so users can save their configuration mid-flow and resume later.

## Key Files

| File | Operation | Description |
|------|-----------|-------------|
| `app/Services/Shared/State/ImportStateManager.php` | NEW | Unified state manager |
| `app/Http/Middleware/ImportStepGuard.php` | NEW | Step validation middleware |
| `app/Http/Controllers/IndexController.php` | MODIFY | Resume import section, less destructive flush |
| `app/Http/Controllers/Import/UploadController.php` | MODIFY | Set active import in state manager |
| `app/Services/BasisBank/Authentication/SecretManager.php` | MODIFY | Also persist to job JSON |
| `routes/web.php` | MODIFY | Register middleware on import step routes |

## Risks

| Risk | Mitigation |
|------|------------|
| Provider credentials in job JSON on disk (security) | Use Laravel Crypt for sensitive fields in the JSON |
| Middleware adds latency to every import step | Middleware is lightweight — single file read |
| Resume could expose stale bank sessions | Validate session on resume, redirect to re-auth if expired |
| Breaking existing import flows | Middleware only adds validation, doesn't change step logic |

## Checklist

- [x] Phase 1: ImportStateManager created with step validation
- [x] Phase 2: ImportStepGuard middleware registered on routes
- [x] Phase 3: Provider auth persisted to job JSON (via ProviderSecretStore + ImportJob.providerAuth)
- [x] Phase 4: "Resume import" on index page (IndexController.getRecentJobs, <24h, resumable states)
- [x] Phase 5: "Start over" preserves Firefly III auth (flush captures/restores baseUrl, accessToken, etc.)
- [x] Phase 6: "Download configuration" on configure, mapping, convert, and submit views
