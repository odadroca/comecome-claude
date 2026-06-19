# Sprint — Security & Deployment Foundations, Pt 2

> **Status: SPECCED, ready to schedule.** One consolidated sprint, **threat-ordered**. Pt 1
> (the dependency-free `tests/run.php` harness) already shipped in Sprint 4 (decision v).
> Folds the two open security topics — **auth/transport hardening** and **at-rest encryption** —
> into a single sprint, with encryption handled **honestly for Hostinger shared hosting**.
>
> **Child boundary:** *no child-VISIBLE UX change.* (Note: CSRF protection does edit child-page
> inline `fetch()` JS to attach a token header — invisible, but child files *are* touched, so the
> child log/celebrate flow must be re-smoke-tested. This is **not** "zero child files.")
>
> Read with [`DECISIONS.md`](DECISIONS.md) (decision v + the encryption amendment below).

## Why single, and why this order

The assessment (read against live code) found the perimeter essentially unhardened, with the
**most-likely real compromises all present**: a 4-digit PIN (10,000-space, enumerable user_ids)
with **zero throttling** and a known default `0000`; **plain HTTP** with unflagged session
cookies; **session fixation** (no `session_regenerate_id` anywhere); and **app-wide missing
CSRF**. At-rest encryption addresses **none** of these — so it goes **last**. One sprint because
the four perimeter fixes share one code surface and one regression pass (Secure cookies are inert
without TLS; `SameSite=Lax` is the cheap half of CSRF; `session_regenerate_id` is two lines in the
same `authenticateUser()` that throttling instruments). **Forced-split fallback:** if capacity-cut,
split only at the Phase 4|5 seam — Phases 0–4 = Pt 2a, Phase 5 = Pt 2b. Never split inside the
perimeter (0–3).

## At-rest encryption — the honest verdict (amends decision v)

- **SQLCipher is INFEASIBLE on Hostinger shared hosting.** It needs `libsqlcipher` at the system
  level + a PHP `pdo_sqlite` linked against it; shared hosting gives no root, no apt, no compiler,
  and no control of the shared vendor PHP build. **Critically, a `PRAGMA key` against stock
  `libsqlite3` is a SILENT NO-OP** — it neither encrypts nor errors, leaving the DB fully plaintext
  while *appearing* to work. `getDB()` uses stock `new PDO('sqlite:'.DB_PATH)` (`includes/db.php:17`).
  **Do not ship SQLCipher instructions to shared-hosting users.**
- **Deployable mechanism instead:** (1) **cheap wins** — move `data.db` *and* the key file **above
  `public_html`** (today `DB_PATH = __DIR__.'/db/data.db'`, inside the tree) and **encrypt off-host
  backups** (`backupDatabase()` is a raw plaintext `copy()`, `db.php:206`); (2) **scoped
  application-level field encryption** with **stock libsodium** (`sodium_crypto_secretbox` /
  XChaCha20-Poly1305 AEAD, bundled PHP 7.2+, no build, no deps) on **only** high-sensitivity
  free-text/identity columns that are never filtered/aggregated.
- **SQLCipher → VPS-only future**, with a concrete trigger ("if/when ComeCome moves to a VPS/Docker
  where the operator controls system packages + the PHP build"). A hosting migration, not a code
  change — must not block protecting data now.
- **Honest ceiling:** a leaked `.db` still exposes schema, indexes, row ownership, and all
  numeric/ordinal/date data — weaker than SQLCipher's opaque whole-file encryption, but the
  realistic ceiling on shared hosting. Failure mode is softer (lost key = lost encrypted *fields*,
  not the whole DB).

---

## Phases (threat-ordered)

### Phase 0 — Stop the no-effort takeovers (XS, ~½ day)
- **Refactor the session bootstrap into a testable function** (critique fix): extract a
  side-effect-free `configureSessionCookieParams()` returning the params array, called by
  `config.php` before `session_start()`. Set `httponly=true`, `samesite='Lax'`, and
  `secure=(HTTPS on)` — Secure auto-enables once TLS lands (Phase 2) without breaking local
  `php -S` dev. (The function is what `tests/run.php` can assert; see Testability.)
- **`session_regenerate_id(true)`** immediately after the successful `password_verify` in
  `authenticateUser()` (`includes/auth.php`) — closes session fixation (2 lines).
- **Force-change the default `0000` PIN:** keep the seed for zero-config first boot, but set a
  `guardian_pin_is_default` flag (only when the stored hash still verifies `0000`) and redirect to
  the change-PIN form until cleared. **Define the flag under `resetDatabase()`/`restoreDatabase()`**
  (critique fix) so a restore/reset can't wrongly re/un-lock.
- **Sole-guardian recovery path** (critique fix): ship a filesystem-run PIN-reset script so the
  force-change + throttling can never permanently lock the only admin out on first boot.
- **Idle timeout:** wire the unused `SESSION_LIFETIME` into `isLoggedIn()`/`requireAuth()`.
- **Fail-safe `getDB()`:** `error_log()` the real message, return a generic error (stop leaking the
  DB path/driver).

### Phase 1 — PIN brute-force throttling + lockout (S, ~1 day) — the #1 named threat
- **Storage = a single AGGREGATED row** per `(user_id[, ip-bucket])` holding `fail_count`,
  `window_start`, `locked_until`, **UPDATE-in-place** (critique fix — *not* one insert per attempt,
  which would write-storm SQLite's single-writer lock under the very flood it defends against).
  Additive **v5→v6** migration (`login_attempts`), mirrored in `db/schema.sql`.
- **Instrument every `password_verify` call site** (critique fix), not just login: also
  `update_self`'s `current_pin` check (`manage-users.php:52`). Progressive backoff → temporary
  lockout; return a distinct `locked` state (not "wrong PIN"); never reveal whether a user exists.
- **Thresholds resolved in-sprint** (critique fix): **per-`user_id` is the primary counter**;
  per-IP only a *loose* ceiling (households share one NAT IP — avoid self-DoS). Defaults, e.g.
  5 fails/15 min per user → 15 min lock; per-IP cap high (e.g. 50/15 min).
- Self-prune via the existing `cleanExpiredTokens()` piggyback (no cron).
- Harness: throttling round-trip phase.

### Phase 2 — Enforce TLS/HTTPS + HSTS (XS–S, ~½ day)
- Uncomment the HTTP→HTTPS 301 in `.htaccess:9-11`; add HSTS (conservative `max-age` first, no
  preload). **Ordering invariant:** the env-gated Secure flag (Phase 0) must precede this so local
  `php -S` HTTP dev never breaks.
- **Hostinger-concrete deploy doc:** "enable free Let's Encrypt SSL in hPanel → uncomment the
  `.htaccess` redirect → confirm HSTS" (replaces decision v's abstract reverse-proxy framing).

### Phase 3 — CSRF + output-escaping + guest-token revocation (M, ~1–2 days)
- Minimal vanilla CSRF helper (`csrfToken()`/`csrfField()`/`verifyCsrf()` via `random_bytes` +
  `hash_equals`). Embed in **every** state-changing form/handler: login, the four `manage-users`
  actions, DB reset/restore.
- **Six `api/` endpoints** require an `X-CSRF-Token` header; inject the token into the page so the
  existing inline `fetch()` calls add it. **This edits `pages/child/*.php` inline JS** (e.g.
  `log-food.php:325-327,374-376`) — invisible, but child files are touched → **add child-path
  regression to acceptance** (critique fix).
- Fold in the same-file low/medium fixes: escape login-page `avatar_emoji`/name (`textContent`, not
  `innerHTML`) and the login error (guardian-editable translations).
- **Guest-token revocation:** additive `guest_tokens.is_revoked` (v5→v6) + check in
  `validateGuestToken()` + a guardian revoke/regenerate control.
- Note: `SameSite=Lax` (Phase 0) and the api `Content-Type: application/json` preflight already
  blunt naive CSRF — frame this as defense-in-depth, correctly ordered below throttling/TLS.

### Phase 4 — `.env` / secrets pattern (S, ~½–1 day)
- Tiny vanilla loader (no Composer/vlucas) that, if a config file **above `public_html`** exists,
  overrides selected `define()`s; falls back to current hardcoded defaults (zero-config first run
  intact).
- **Key container precisely specified** (critique fix): the field-encryption key is **32 bytes,
  base64-encoded, in a PHP file (`return '...';`) required from above docroot at `0400`** — *not*
  raw bytes in an `.ini` (`parse_ini_file` mangles binary/reserved tokens). Validate decoded length;
  fail closed. **Key must never sit in the same backup archive as the DB.**

### Phase 5 — At-rest data protection (cheap wins XS; field encryption M) — GO-gated, last
- **GO gate:** merge the DECISIONS.md amendment (below) + a written go decision **before** any
  encrypt-on-write code lands.
- **Cheap wins first (zero crypto):** move `data.db` **and** the backup target above `public_html`
  (critique caveat: `backupDatabase()` writes into the tree — relocate it too, don't rely solely on
  the `.htaccess` `FilesMatch` deny, which evaporates under nginx/litespeed); encrypt off-host
  backups (age/gpg).
- **Scoped libsodium field encryption** (`includes/crypto.php`, encrypt-on-write/decrypt-on-read):
  apply to **`users.name`, `daily_checkin.notes`, `medications.name`, `medications.dose`** only.
- **EXCLUDE `gender` + `date_of_birth` from encryption in this sprint** (critique fix): the WHO
  percentile engine consumes them to derive age, and they flow through `getReportData` /
  `getDashboardData` / `computePercentileSummary` + the 4 export surfaces. Encrypting them would
  silently break percentile output unless decrypt is wired into all those hot paths. Keep them
  cleartext (protected by DB-above-docroot + access control); **encrypting DOB is a documented
  follow-up** that must wire decrypt into the percentile read paths and re-run the E-phase harness.
- **Leave all numeric/ordinal/date/coded columns cleartext** (`appetite_level`, `mood_level`,
  `weight_kg`, `height_cm`, portions, dates, `food_log.med_window`) so dashboard
  WHERE/ORDER/JOIN/aggregations + correlations keep working.
- **Verify-first one-time backfill** (critique fix): dry-run that decrypts back and asserts
  **byte-identical round-trip on every non-ASCII pt-PT name/note** before committing; take a one-shot
  plaintext snapshot that is encrypted/destroyed immediately after; idempotency keyed on a per-value
  sentinel so a half-run is safely resumable; decrypt-on-read transparently handles both
  encrypted and not-yet-backfilled values during transition.
- SQLCipher **deferred to VPS** (documented trigger only; reuse `PLAN-db-encryption.md`).

---

## Cross-cutting rules
- **Stock PHP only** — libsodium/openssl (bundled 7.2+), `random_bytes`, `hash_equals`,
  `parse_ini_file`/`require`. No Composer, no PHPUnit, no C build, no `vlucas/phpdotenv`.
- **Every DB change additive + version-gated v5→v6**, mirrored in `db/schema.sql`. New
  table/columns (`login_attempts`, `guest_tokens.is_revoked`) default-safe so existing rows survive.
- **Harness deltas (verified line refs):** bump the `schema_version === 5` asserts at
  `tests/run.php:304, 378, 466, 830` to **6**, and extend the exact-table-set at `run.php:286-292`
  (18 → 19 with `login_attempts`). Keep the v1→…→v6 forward-migration + idempotent-re-run green, and
  mirror every new column in **both** `migrateDatabase()` and `schema.sql` (the A1-vs-A2 parity
  asserts will diverge otherwise).
- **Backward compatibility is a hard gate:** existing sessions keep working (regenerate fires only
  on next login; flags only add attributes); existing hashed PINs verify unchanged; non-default
  guardian PINs are never force-reset; current `data.db` migrates + backfills cleanly.

## Testability (the critique's #1 gap — addressed)
The CLI harness **cannot** load `config.php`/`session_start()` or assert HTTP cookie/redirect
behavior. So:
- Phase 0's logic lives in the **extracted `configureSessionCookieParams()`** function the harness
  *can* call and assert (flags, idle-timeout math).
- Add a small **HTTP-level smoke test** (`php -S` in background + `curl -I`) asserting `Set-Cookie`
  carries `HttpOnly`+`SameSite=Lax`, and that the 301 redirect + HSTS fire when the TLS env flag is
  set. Without this, "exercised by the harness" is false for Phases 0/2.
- Phases 1/3/5 add: throttling round-trip; CSRF-reject (guardian action + an api endpoint);
  encrypt/decrypt round-trip + tamper→AEAD-fail + backfill idempotency. Throwaway temp DBs only.

## Acceptance (per phase)
0. Fresh install can't reach the dashboard until `0000` is changed; cookie carries
   `HttpOnly`+`SameSite=Lax` (+`Secure` on TLS); login rotates the session id; idle past
   `SESSION_LIFETIME` logs out; DB errors return generic text; sole-guardian reset script works.
1. Scripted wrong-PINs hit backoff→lockout (distinct `locked` state) on **every** verify site;
   correct PIN resets; `login_attempts` is a single updated row; `schema_version`=6, table set
   includes `login_attempts`.
2. HTTP 301→HTTPS on Hostinger; HSTS present over TLS; Secure flag active in prod; local `php -S`
   still HTTP.
3. State-changing POSTs without a valid token are rejected (4xx); with token succeed; login-page
   name/emoji render inert; revoked guest token fails; **child log/celebrate flow re-smoke-passes**.
4. Config file above docroot overrides; absent → defaults (zero-config intact); secrets gitignored;
   base64 key validates/fails-closed.
5. DECISIONS.md amendment + GO merged before crypto; `data.db`+key+backups above docroot; off-host
   backups encrypted; leaked raw `.db` shows `users.name`/`notes`/`medications.name`+`dose` as
   ciphertext while **percentile analytics stay fully functional** (gender/DOB cleartext by design);
   backfill runs once with verify + no-op re-run; SQLCipher not shipped to shared hosting.

## Open questions
- HSTS `max-age` / preload aggressiveness (operator risk tolerance).
- Per-token vs revoke-all guest-token control (UX scope).
- Field-key rotation: single-key-for-life for v1, or ship a rotation path? (re-encrypt tooling).
- DOB-encryption follow-up timing (needs decrypt wired into percentile paths).
