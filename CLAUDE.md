# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

ComeCome is an ADHD-friendly food and nutrition tracking PWA for neuro-divergent children, built with vanilla PHP + JavaScript and SQLite. No build step, no frameworks, no external runtime dependencies beyond Pico CSS (+ Chart.js via CDN). **Guiding principle:** the child interaction surface stays deliberately flat (emoji-first, tap-portion-celebrate, ≤5 footer items); all new depth goes to the guardian/clinician layers.

> **Repo model:** this is **`comecome-claude` = STAGING** (deployed to Hostinger for live testing). The public **production** repo is **`Come-come`**. Land + test here; promote to public as a deliberate, reviewed step. Never push WIP to public.

## Development Server

```bash
php -S localhost:8000
```
PHP needs **SQLite3**, and (only for at-rest encryption) the **`sodium`** extension. The DB auto-creates/migrates on first request. The DB path is `DB_PATH` from `config.php`, overridable per-deployment via a **git-ignored `config.local.php`** (or the `COMECOME_DB_PATH` env var) — used in production to keep `data.db` above the web root. Template: `config.local.php.example`.

## Testing

```bash
php tests/run.php
```
The **single dependency-free regression entry point** (no Composer/PHPUnit). Runs only against throwaway temp DBs (never `db/data.db`); exits non-zero on failure. Covers initialize / migrate-idempotency / backup-restore, the cumulative smoke (`tests/smoke.php`), the **HTTP-behavior smokes** (`tests/http_*_smoke.php` — cookies, TLS/HSTS, CSRF incl. the child flow, throttle lockout, field encryption, secrets), a negative self-test, and per-feature phases (percentiles, med-timing, security 0–5). See `tests/README.md`.

## Architecture

**Routing**: `index.php` is the single entry point. Routes via `?page=` (switch-case router); server-rendered PHP buffered through `renderLayout()` (which also injects the CSRF meta token). A router gate redirects a still-default-`0000` guardian to change their PIN. A second gate (Launch Sprint 2) holds any guardian who hasn't acknowledged the current privacy/consent notice on `pages/consent.php` until they consent — state is the `guardian_consent_version` `settings` row vs the `CONSENT_NOTICE_VERSION` constant (helpers `guardianConsentCurrent()`/`recordGuardianConsent()` in `includes/auth.php`); it is conditioned on `!guardianPinIsDefault()` so it never loops with the PIN gate, and a child reaching the app pre-consent sees a neutral "not set up yet" view, never the consent form. A guardian-only `?page=safeguarding` route (`pages/guardian/safeguarding.php`) surfaces per-child wellbeing flags from `computeSafeguardingFlags()` (`includes/safeguarding.php`); the guardian nav (`pages/guardian/nav.php`) shows a numeric badge on the "Wellbeing" item when flags are live; both are gated by the `show_safeguarding_alerts` setting (default on), enforced inside `computeSafeguardingFlags()` so "off" suppresses flags everywhere.

**Auth & sessions**: PIN-based (4-digit, hashed). Roles `child`/`guardian` with `requireAuth()`/`requireGuardian()`. `includes/session.php` hardens cookies (HttpOnly / SameSite / env-gated Secure) via `configureSessionCookieParams()` and enforces TLS + HSTS; login calls `session_regenerate_id()`; idle timeout uses `SESSION_LIFETIME`. PIN brute-force is throttled in `includes/throttle.php` (single aggregated `login_attempts` row; per-user primary + loose per-IP). Guest tokens (now **revocable**) give time-limited clinician access.

**CSRF**: `includes/csrf.php` — every state-changing POST (all guardian forms + the 6 `api/` endpoints) requires a token (`hash_equals`); child-page inline `fetch()` calls attach `X-CSRF-Token`.

**Database**: SQLite3 via PDO, prepared statements everywhere, `PRAGMA busy_timeout`. **`schema_version` = 8**, migrated additively by `migrateDatabase()`. Tables: `users` (incl. `gender`, `date_of_birth`), `meals`, `food_categories`, `meal_categories`, `foods`, `food_growth_tags`, `user_favorites`, `food_log` (incl. `med_window`), `medications`, `user_medications`, `medication_schedules`, `daily_checkin` (incl. `sleep_quality`), `weight_log`, `height_log`, `settings` (key/value), `guest_tokens` (incl. `is_revoked`), `translations`, `login_attempts`, `sleep_log`, `sleep_interruptions`, `data_deletion_log` (PII-free audit: `scope` `'child'`|`'retention_purge'`, `record_counts` JSON; written by `writeDeletionAudit()` in `includes/retention.php`).

**Nutrition intelligence (Sprint 11)**: `includes/nutrition.php` — rule-based (NOT AI), guardian/clinician-side only, gated by `show_nutrition_insights` (default `'0'`). Three deterministic analyzers over `food_growth_tags` + `food_log.med_window` + percentiles + sleep: medication-timing distribution, weekly growth-tag coverage/trend, and a templated recommendation engine (tunable thresholds as `NI_*` constants). Surfaced on the guardian dashboard + the clinician "Medication-Aware Nutrition Summary" (HTML/CSV/JSON/guest-report, JSON-whitelisted as de-identified aggregates). ZERO child-facing surface.

**At-rest encryption (opt-in)**: `includes/crypto.php` — libsodium `enc:v1:` envelope on **scoped sensitive columns** (`users.name`, `daily_checkin.notes`, `medications.name`/`dose`) **when a key is configured** (`includes/secrets.php` + `config.local.php`). With no key, those columns stay plaintext (zero-config). Fails **closed** on tamper / wrong key / missing sodium. `gender`/`date_of_birth` and all numeric/date/coded columns stay cleartext (the percentile engine + dashboard aggregations depend on them). Backfill: `scripts/encrypt-backfill.php` (verify-first, idempotent).

**Growth & percentiles**: `includes/growth-standards.php` (WHO 2006 + 2007 LMS reference data) + `includes/percentiles.php` (z-score → percentile engine; **WHO-first**, CDC 2–19y is a planned additive follow-on). Surfaced in the **guardian dashboard + clinician reports only** — the child "Growth" chart stays an encouraging line with no clinical bands. Gated by `show_percentiles` (needs gender + DOB; graceful prompt otherwise). Guardians enter each child's **sex + date of birth on the Manage Users add/edit form** (shown for children only); DOB is server-validated by `validBirthDate()` in `includes/helpers.php` (rejects future/malformed → null, never clamps).

**Medication timing**: `includes/medication.php` — `medication_schedules` + `computeMedWindow()` stamps `food_log.med_window` (pre_med/onset/mid_med/post_med) **server-side at insert**, invisible to the child; feeds clinician nutrition analysis.

**Data deletion & export helpers**: `includes/retention.php` — `eraseChildData()` (whole-child cascade delete with FK enforcement, per-table count gathering) + `writeDeletionAudit()` (writes `data_deletion_log`). `includes/helpers.php` — `buildFullHistoryReport()` + `buildWholeDbExport()` (export-all JSON builders, projected through `projectReportForJson()`).

**API**: JSON endpoints in `api/` (food-log, check-in, weight, height, favorites, sleep). Auth + per-user ownership enforced; CSRF-required on writes.

**i18n**: key-based from `locales/{pt,en}.json` (pt canonical + default). DB `translations` table overrides at runtime. Use `t('key', $params)`. pt/en key parity is asserted by the harness.

**Frontend / PWA**: vanilla JS (`assets/js/app.js`); a small hand-rolled `assets/css/pico.min.css` mini-framework (NOT real Pico — its `--primary` is the legacy green, overridden to teal in the theme) + `assets/css/custom.css` (ADHD-optimized base) + **`assets/css/comecome-theme.css`** = the current **"Lagoon" teal design system** (drop-in override layer loaded AFTER custom.css; tokens + warm dark mode; guardian sections render as cards, dashboard single-column). **Bump `sw.js CACHE_NAME` on any theme/child-asset change.** Fonts (Lexend + Atkinson Hyperlegible) are **self-hosted** in `assets/fonts/` (no Google Fonts CDN; OFL licenses shipped). Service worker `sw.js` (cache-first assets incl. theme + fonts, network-first pages). Design handovers live (git-ignored) in `design-handover/<pkg>/` — **append/cherry-pick** their blocks onto the live theme (they predate later fixes); verify with headless Chrome against each package's `design-reference/` previews.

> **Finding (2026-06-22): the "Pico CSS" label is a legacy misnomer.** `assets/css/pico.min.css` is hand-rolled custom CSS, **not** the Pico framework (verified: ~2.5 KB, zero `--pico-*` vars), and no real Pico is loaded anywhere. So the Overview's "Pico CSS" dependency line is inaccurate and there is **no third-party Pico license to attribute** (hence none in `NOTICE`). Minor leftover: `pages/guardian/manage-sleep.php` (~L208/250) still references the real-Pico var `var(--pico-muted-border-color)`, which nothing defines → those borders render with no color. Naming consistency + this leftover are owned by a separate agent.

## Feature Toggles
Guardian Settings toggles (key/value in `settings`, via `getSetting('key','default')`): `show_food_journal`, `show_checkin`, `show_weight_tracking`, `show_sleep_tracking`, `show_medication_to_children`, **`show_percentiles`** (default `'0'`), **`show_nutrition_insights`** (default `'0'`, guardian/clinician-side; no child route). Child routes in `index.php` enforce them; the shared footer (`pages/child/footer.php`) renders ≤5 buttons conditionally. New child feature → add a setting, guard the route, add the footer button.

## Scripts (CLI-only, filesystem-run)
- `scripts/gen-key.php` — generate the field-encryption key container (above docroot, `0400`).
- `scripts/encrypt-backfill.php` — verify-first, idempotent backfill of the scoped encrypted columns.
- `scripts/reset-pin.php` — sole-guardian PIN recovery from the server filesystem.
- `db/seed-demo.php` — 3-month demo dataset (anonymized children) for live testing; `--reset` to re-seed.

## Key Conventions
- PHP fns **camelCase**; DB columns/tables **snake_case** (plural tables); translation keys `section_descriptor`; CSS **kebab-case**.
- Logic in `includes/`, presentation in `pages/`, constants in `config.php`.
- **Docs ship with the code.** Any feature or sprint PR MUST update `CHANGELOG.md` `[Unreleased]` and the relevant `CLAUDE.md` section in the **same PR** — never as a follow-up. (A4 safeguarding shipped in PR #31 without this and had to be back-filled.)
- **Every schema change** = an additive, version-gated `migrateDatabase()` block **mirrored in `db/schema.sql`**, and update the `tests/run.php` `schema_version` + exact-table-set asserts.
- **Bump `sw.js CACHE_NAME`** on any child-page/asset change. Keep the four export surfaces (HTML/CSV/JSON/guest-report) in parity; whitelist the JSON projection (never emit `pin`/raw `date_of_birth`).
- **Access gates cover BOTH write surfaces.** Data can be written two ways — the `?page=` switch router (`index.php`) and the `api/*.php` endpoints — so any gate that must block writes (e.g. the guardian **consent** gate) has to be enforced in **both**: a router-level check in `index.php` AND a guard in every API endpoint (alongside `requireCsrfForApi()` — see `requireConsentForApi()`), sharing one predicate (e.g. `guardianConsentCurrent()`), with a **regression test at each surface** (the consent smoke asserts the `manage-users` page redirect *and* a 403 `consent_required` on `api/check-in` pre-consent). **CSRF is not an access gate** — it stops cross-site forgery, not an authenticated-but-unauthorized actor. Adding such a guard usually means updating sibling API smokes that didn't set up the new precondition.

## Page Organization
- `pages/child/` — log-food, check-in, weight (becomes "Growth" when `show_percentiles` on), history. Own data only; shared footer.
- `pages/guardian/` — dashboard, **manage-users** (child + guardian admin — child **sex/DOB** demographics live here; also has a **"Danger zone" whole-child erasure** requiring the child's exact name + typed confirm word `DELETE`/`ELIMINAR`, CSRF-gated, audited via `eraseChildData()` → `data_deletion_log`), manage-foods/meals/medications/sleep/logs, settings, exports, database backup/restore, **safeguarding** (wellbeing flags, guardian-only); shared `nav.php`. ⚠️ **`manage-children.php` is DEAD CODE** — `index.php` routes both `manage-children` and `manage-users` to `manage-users.php` (backward-compat alias); edit `manage-users.php`, not `manage-children.php`. Every guardian POST handler has a `verifyCsrf()` gate; every guardian `<form method="POST">` has `csrfField()`. Exports split across `export.php` (coordinator; also offers **export-all**: per-child full-history + whole-DB JSON bundle via `buildFullHistoryReport()`/`buildWholeDbExport()` in `includes/helpers.php`, projected through the `projectReportForJson()` whitelist) + `export-csv.php` + `export-html.php` (keep the four export surfaces in parity).
- `pages/translations.php` (guardian-accessible runtime translation overrides), `pages/login.php` (public auth), `pages/guest-report.php` (token-validated clinician report).

## Timezone & Locale
App timezone `Europe/Lisbon`; default locale `pt`; date display dd-mm-yyyy.

## Roadmap & Status
Canonical plan: `.claude/SPRINT-PLAN_reconciled.md`. Decisions: `docs/roadmap/DECISIONS.md`. Specs: `docs/roadmap/SPRINT-{03,10,11,SECURITY}.md`. `CHANGELOG.md` records the cycle. **As of 2026-06-22:** Sprints 3–11 + the Security sprint are **built (schema v7)** — Sprint 11 (Growth-Support Nutrition Intelligence, rule-based) landed `food_growth_tags` + `includes/nutrition.php` + the guardian/clinician surfaces; `php tests/run.php` green (332 tests). Version **reconciled to v0.11.0** (README / `config.php` `APP_VERSION` / `sw.js` `CACHE_NAME`) and tagged on staging; **v0.11.0** adds the Lagoon teal recolor, the guardian-cards layout pass, the child food-log undo, and self-hosted fonts (privacy). A post-0.11.0 **`[Unreleased]`** batch (on `main`; `sw.js` `comecome-v0.11.2`; `php tests/run.php` **359 green**) adds the guardian **sex/DOB input** (unblocks WHO percentiles, with `validBirthDate()`), **completes CSRF** on every guardian POST page, **stacks the dashboard charts** (regression fix), **backdated meal logging**, roots out the Pico-`--primary` green-button leak, and a **guardian add-item hardening** pass (PRs #25/#27): adding a duplicate food/category/meal name no longer 500s (the create handlers wrap the dispatch in `try/catch` → a friendly *already exists* message), and the add-forms now **auto-derive the i18n key** from the display name (`slugifyTranslationKey()` in `includes/helpers.php` + a live "saved as" preview + an *Advanced* raw-key override) so guardians never hand-author keys. Pending = **promote staging→prod**, plus backlog (follow-on 8b CDC; height chart; per-child toggles; optional opt-in narrative-only LLM layer — see `docs/roadmap/SPRINT-11-nutrition-intelligence.md` §6).

## Production Hardening
See `README.md` → "Production Hardening" and `docs/roadmap/SPRINT-SECURITY.md`: HTTPS/HSTS, DB above docroot via `config.local.php`, off-host encrypted backups, opt-in field encryption (needs `sodium`). **SQLCipher is deferred (VPS-only)** — infeasible on shared hosting (a `PRAGMA key` on stock SQLite is a silent plaintext no-op).
