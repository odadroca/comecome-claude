# ComeCome Tests

Dependency-free PHP test harness — **no Composer, no PHPUnit, no build step**.
Every test runs against a **throwaway temp SQLite database** and NEVER touches
`db/data.db`.

## Single regression command

```bash
php tests/run.php
```

Exit code `0` = all checks passed; non-zero = one or more failures. This is the
**single regression entry point**: it runs new in-process unit checks AND folds
in the existing sub-runners so coverage stays cumulative across Sprints 0–4.

| Phase | What it covers |
|---|---|
| A1 | `initializeDatabase()` on a fresh temp DB → all 16 expected tables exist, `schema_version` reaches 2, default guardian seeded. |
| A2 | `migrateDatabase()` forward from a **synthetic v1 fixture** → `daily_checkin.sleep_quality` + `sleep_log` + `sleep_interruptions` appear, pre-existing rows survive; re-running migrate twice is a no-op (version unchanged, no error). |
| A3 | `backupDatabase()` / `restoreDatabase()` round-trip → write → backup → mutate → restore → assert original restored. |
| B  | Orchestrates `tests/migration_idempotency.php` and `tests/smoke.php` as isolated child processes (each must exit 0). Smoke = cumulative Sprint 0–3 (auth, toggles, sleep, page renders, clinical-report correlations, JSON pin whitelist, pt/en i18n parity). |
| C  | **Negative self-test** — re-invokes the runner in `--selftest-negative` mode (which fails by design) and asserts a non-zero exit, proving the harness catches a deliberately broken case. |
| E/F | Sprint 5/6/8/9 percentile + medication-timing coverage (`schema_version` reaches 5). |
| G  | **Security Phase 0** — `configureSessionCookieParams()` cookie-flag logic (HttpOnly + SameSite=Lax always; Secure env-gated on HTTPS / proxy / :443); `sessionIsExpired()` idle-timeout math (incl. legacy null-stamp backward compat); default-`0000`-PIN guard lifecycle (fresh init arms it; a PIN change off `0000` clears it; restore/reset of a `0000` DB re-arms it; a custom-PIN guardian is never flagged). All re-derived from the actual stored hash. |
| H  | **Security Phase 1** — PIN brute-force throttling/lockout. `throttleComputeAfterFailure()` backoff/window/lock state machine + `throttleIsLocked()` (pure, no DB); `authenticateUser()` round-trip on a throwaway DB: scripted wrong-PINs tip into a **distinct** locked state, a locked account refuses even the correct PIN, a correct PIN resets the counter, storage stays **one** aggregated row (UPDATE-in-place), an unknown id locks identically (no existence oracle), self-prune drops stale rows. `schema_version` reaches **6** (`login_attempts`). |
| I  | **Security Phase 2** — enforce TLS/HTTPS + HSTS. Pure transport-security decision logic (`includes/session.php`): `requestIsHttps()` (TLS / `X-Forwarded-Proto` / `:443`, matching Phase 0's Secure logic); `httpsEnforcementEnabled()` (only truthy `COMECOME_FORCE_HTTPS` arms it, so unset = dev-safe); `httpsRedirectTarget()` (301 to same-host `https://` preserving path+query **only** on plain HTTP + enforcement on; never an already-HTTPS request → no loop; never with the flag off; CR/LF stripped from a crafted Host → no header injection); `hstsHeaderValue()` (conservative `max-age=86400; includeSubDomains`, **no preload**, emitted **only** over HTTPS per RFC 6797). **No DB change** (no schema bump). |
| B2 | **HTTP-level smokes** orchestrated as file-redirected sub-runners (spawn `php -S` + curl, each on a free ephemeral port, each exit 0): `tests/http_smoke.php` (Phase 0 cookie flags), `tests/http_throttle_smoke.php` (Phase 1 lockout message over real HTTP), and `tests/http_tls_smoke.php` (Phase 2 HTTP→HTTPS 301 + HSTS header over real HTTP). |

## Sub-runners (can also be run directly)

```bash
php tests/smoke.php                  # cumulative smoke (Sprints 0–3)
php tests/migration_idempotency.php  # migrate forward + idempotency
php tests/http_smoke.php             # HTTP-level: php -S + Set-Cookie header flags (Phase 0)
php tests/http_throttle_smoke.php    # HTTP-level: php -S + scripted wrong-PIN POSTs -> lockout (Phase 1)
php tests/http_tls_smoke.php         # HTTP-level: php -S + HTTP->HTTPS 301 + HSTS header (Phase 2)
```

### HTTP smoke (`tests/http_smoke.php`)

The in-process runner cannot load `config.php` or start a real session, so it can
only assert the **pure** cookie-param logic. `tests/http_smoke.php` boots the
built-in `php -S` dev server against a **throwaway DB** (`COMECOME_DB_PATH`) and
inspects the real response headers, asserting the session cookie carries
`HttpOnly` + `SameSite=Lax` and — over plain HTTP dev — **no** `Secure` flag (so
local `php -S` is never broken; Secure auto-enables under TLS in Phase 2). Exit
`0` = pass.

### HTTP throttle smoke (`tests/http_throttle_smoke.php`)

The in-process runner (PHASE H) drives the throttle state machine + DB round-trip
directly, but cannot observe the wired-up **login page** surfacing the distinct
`locked` state over real HTTP. This smoke boots `php -S` against a **throwaway DB**
(`COMECOME_DB_PATH`, asserted non-empty so it never falls back to the real
`db/data.db`) and POSTs scripted wrong PINs to `?page=login`: attempts under the
threshold return the **wrong-PIN** message, the threshold attempt tips into the
**distinct** `login_locked` message, and even the correct PIN is then refused
(the pre-verify lockout gate holds end-to-end). Binds a **free ephemeral port** so
overlapping runs / orphaned servers never collide. Exit `0` = pass.

### HTTP TLS/HSTS smoke (`tests/http_tls_smoke.php`)

The in-process runner (PHASE I) asserts the **pure** transport-security decision
logic, but cannot observe the wired-up app actually 301-redirecting or emitting
the HSTS header over a real request. The `.htaccess` rule is the **primary**
mechanism, but `.htaccess` needs Apache and `php -S` does not run it — so this
smoke exercises the **PHP backstop** (`enforceTransportSecurity()` in
`includes/session.php`, invoked from `config.php`). It boots `php -S` against a
**throwaway DB** (`COMECOME_DB_PATH`) with HTTPS enforcement turned on
(`COMECOME_FORCE_HTTPS=1`) and asserts: a plain-HTTP request returns a **301** to
the same-host `https://` URL (path+query preserved) with **no** HSTS on that
plain-HTTP response; a request treated as TLS via `X-Forwarded-Proto: https`
is served **200** (no proxy redirect loop) and carries
`Strict-Transport-Security: max-age=...; includeSubDomains` with **no preload**;
and a **second** server with enforcement **off** (the zero-config default) does
**neither** over plain HTTP — proving local `php -S` dev is never broken (the
ordering invariant). Binds **free ephemeral ports**. Exit `0` = pass.

## Honesty policy

Assertions are never weakened or skipped to make them pass. A check that cannot
be evaluated FAILS loudly rather than being silently skipped.

## Encryption-timing prerequisite (decision v)

Per `docs/roadmap/DECISIONS.md` decision (v), the **Security & Deployment
Foundations** track unblocks the still-deferred **SQLCipher at-rest encryption**.
The encryption timing review requires a `tests` safety net to exist FIRST, so the
`getDB()` / `initializeDatabase()` / `migrateDatabase()` / `backupDatabase()` /
`restoreDatabase()` paths can be re-validated once the driver changes (i.e. prove
they still behave identically under encryption).

**`php tests/run.php` is that prerequisite.** Do not schedule SQLCipher before
this harness runs green.
