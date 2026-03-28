# BasisBank plan_part_05: Validator and Healthcheck Alignment

Date: 2026-02-16
Owner: Codex
Status: planning
Depends on: PART 03, PART 04

## Files to edit (minimal)
- `data-importer/app/Services/BasisBank/AuthenticationValidator.php`
- `data-importer/app/Services/BasisBank/Request/GetPingRequest.php`

## Files to read only (for pattern)
- `data-importer/app/Http/Controllers/ServiceController.php`
- `data-importer/app/Api/Controllers/ImportFlow/ValidationController.php`

## Execution directive (context hygiene)
- Do not deep-scan or reopen additional files beyond this plan and the listed files.
- Execute this part strictly using this plan context to avoid context overflow.

## Objective
- Align BasisBank auth validation endpoints with session-based auth model.

## Exact implementation scope
- `AuthenticationValidator` must evaluate session/credential state, not token presence.
- Return contract:
- `NODATA`: missing credentials/session.
- `ERROR`: unrecoverable validator failure (non-recoverable runtime faults only).
- `AUTHENTICATED`: session is valid.
- `GetPingRequest` should use web-session auth artifact and be suitable for fast liveness checks.

## Verification target
- `/validate/basisbank` and `/api/import-flows/validate/basisbank` reflect new auth-state contract.

## Progress
- [ ] Implement session-based BasisBank validation semantics and ping request alignment. Status: implemented, awaiting user confirmation. Touched: `app/Services/BasisBank/AuthenticationValidator.php`, `app/Services/BasisBank/Request/GetPingRequest.php`.

## Decision Log
- CHO #1: validation now uses `basisbank_auth_state` + `basisbank_session_artifact` as the contract for status and rejects unknown/auth-failed states as NODATA/ERROR respectively, because the interactive session model is authoritative and does not read API token presence as "authenticated".
- CHO #2: stale/expired session ping failures now downgrade to `NODATA` and clear stale session state, so the index page keeps BasisBank flow interactable via `Authenticate` instead of showing a blocking blank/error row.
