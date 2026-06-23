# Task 1 Report — consent-state helper + notice-version constant

## Status

DONE

## Files Changed

| File | Change |
|---|---|
| `config.php` | Added `define('CONSENT_NOTICE_VERSION', 1);` after `APP_VERSION` (with comment) |
| `includes/auth.php` | Added `guardianConsentCurrent(): bool` and `recordGuardianConsent(): void` helpers near other session/identity helpers, before `isLoggedIn()` |
| `tests/run.php` | Added `define('CONSENT_NOTICE_VERSION', 1);` in the Phase A bootstrap block; added **PHASE O** test block (6 assertions) before the VERDICT |

## TDD Process

1. **RED**: Added Phase O test block + `CONSENT_NOTICE_VERSION` define to `tests/run.php` first; confirmed the functions did not yet exist (would fail with "function not found").
2. **Implement**: Added `CONSENT_NOTICE_VERSION` to `config.php`; added `guardianConsentCurrent()` and `recordGuardianConsent()` to `includes/auth.php`.
3. **GREEN**: Ran isolated Phase O test script.

## Test Command

```
C:/SAP/bin/php/php-8.3.30-Win32-vs16-x64/php.exe tests/run.php
```

## Isolated Phase O Result (confirmed GREEN)

```
=== PHASE O test (isolated) ===

  [PASS] O consent helpers present
  [PASS] O1 fresh DB: guardianConsentCurrent() is false
  [PASS] O2 after recordGuardianConsent(): is true
  [PASS] O2 guardian_consent_at is ISO-8601 timestamp [got: 2026-06-23T10:58:03+00:00]
  [PASS] O3 after version tampered to '999': false again
  [PASS] O4 after version restored: true again

=== Result: 6 passed, 0 failed ===
```

## Full Suite Result

```
Result: 365 passed, 0 failed
RUN: PASS
```

(359 prior + 6 new Phase O assertions)

## Key Design Decisions

- `CONSENT_NOTICE_VERSION` is defined in `config.php` (same block as `APP_VERSION`) — not in `auth.php` — because it is a **deployment constant**, not a helper.
- The test runner **mirrors** the constant (`define('CONSENT_NOTICE_VERSION', 1)`) in its Phase A bootstrap, following the same pattern as `APP_VERSION = 'test'`, `SESSION_LIFETIME = 86400`, etc. This is necessary because `config.php` cannot be loaded by the CLI harness (it calls `session_start()` and `enforceTransportSecurity()`).
- `guardianConsentCurrent()` uses `getSetting('guardian_consent_version', '')` with a default of `''` so it returns `false` (not `true`) when no version is stored — fail-closed for consent.
- `guardianConsentAt()` is not added — only `recordGuardianConsent()` writes the timestamp; callers that need it read via `getSetting('guardian_consent_at')`.
- No schema change: settings table already exists as key/value store.

## Concerns

None — implementation is minimal, correct, and consistent with existing patterns.
