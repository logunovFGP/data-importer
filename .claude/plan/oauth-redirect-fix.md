# Plan: Fix OAuth Redirect After Firefly III Login

## Root Cause (Confirmed via E2E Testing)

**Primary Bug**: Firefly III's Content Security Policy header `form-action 'self'` blocks the login form submission when the user arrives from the data-importer (cross-port redirect).

**Evidence**: Browser console error:
```
Sending form data to 'http://localhost:9999/login' violates the following
Content Security Policy directive: "form-action 'self'". The request has been blocked.
```

**Secondary Bug**: After the CSP block, the user manually re-navigates to `localhost:9998`, which creates a new session with a new `state` value. When a stale OAuth callback arrives, the state doesn't match → 500 error "The state returned from your server doesn't match the state that was sent."

**Flow trace (from Playwright + nginx logs)**:
```
1. User enters Client ID 3 → data-importer stores state/code_verifier in session
2. Redirect to localhost:9999/oauth/authorize → 302 to /login (not authenticated)
3. Login page shown → user enters credentials → clicks Sign In
4. POST /login → BLOCKED by CSP form-action 'self' → page stays on login
5. User manually navigates to localhost:9998 → new session (new state)
6. If old callback arrives → state mismatch → 500 error
```

**CSP source**: `firefly-iii/app/Http/Middleware/SecureHeaders.php:84-86`
```php
if (null !== $route && 'oauth/authorize' !== $route->uri) {
    $csp[] = sprintf("form-action 'self' %s", $customUrl);
}
```

## Fix

### Option A: Set `DISABLE_CSP_HEADER=true` in `.env` (quick fix)

**File**: `firefly-iii/.env`
**Change**: Add `DISABLE_CSP_HEADER=true`

Pros: Immediate fix, no code changes
Cons: Disables ALL CSP protection (security regression)

### Option B: Exempt the `/login` route from form-action CSP (targeted fix)

**File**: `firefly-iii/app/Http/Middleware/SecureHeaders.php`
**Change**: Skip `form-action` for `/login` route (same as `/oauth/authorize`)

```php
// Before:
if (null !== $route && 'oauth/authorize' !== $route->uri) {
    $csp[] = sprintf("form-action 'self' %s", $customUrl);
}

// After:
$skipFormAction = ['oauth/authorize', 'login'];
if (null !== $route && !in_array($route->uri, $skipFormAction, true)) {
    $csp[] = sprintf("form-action 'self' %s", $customUrl);
}
```

Pros: Minimal change, login form works from any referrer
Cons: Removes form-action protection from login page only

### Option C: Add data-importer origin to form-action whitelist (recommended)

**File**: `firefly-iii/.env`
**Change**: Add a config variable for allowed form-action origins

**File**: `firefly-iii/app/Http/Middleware/SecureHeaders.php`
**Change**: Include the importer's origin in form-action:

```php
$allowedFormActions = config('firefly.allowed_form_origins', '');
$csp[] = sprintf("form-action 'self' %s %s", $customUrl, $allowedFormActions);
```

**File**: `firefly-iii/config/firefly.php`
**Change**: Add `'allowed_form_origins' => env('ALLOWED_FORM_ORIGINS', '')`

Then in `.env`: `ALLOWED_FORM_ORIGINS=http://localhost:9998`

Pros: Explicit whitelist, maintains CSP for other pages
Cons: More changes, user must configure

### Recommended: Option B

The login form should accept submissions regardless of where the user came from — CSP `form-action 'self'` on the login page is overly restrictive since:
1. The form action IS same-origin (`localhost:9999/login` → `localhost:9999/login`)
2. The CSP violation appears to be a Chrome behavior with cross-port referrers
3. The `/oauth/authorize` route is already exempted with the same pattern

## Key Files

| File | Operation | Description |
|------|-----------|-------------|
| `firefly-iii/app/Http/Middleware/SecureHeaders.php:84-86` | MODIFY | Exempt login route from form-action CSP |
| `firefly-iii/.env` | CHECK | Verify `DISABLE_CSP_HEADER` is not set |

## Verification

1. Clear all sessions (flush data-importer + logout Firefly III)
2. Navigate to `localhost:9998`
3. Enter Client ID 3
4. Login on Firefly III → form submission should work
5. Click Authorize on OAuth page
6. Callback should reach data-importer with valid state
7. Token exchange should succeed
8. Data-importer should show the import index page

## Checklist

- [x] Fix CSP form-action in SecureHeaders.php (commit 9667629)
- [x] Verify login form works after cross-port redirect (0 console errors)
- [x] Verify full OAuth flow completes end-to-end (Playwright E2E: token → login → authorize → callback → index)
- [x] Verify CSP still protects other pages (curl confirmed form-action 'self' on / but not /login)
