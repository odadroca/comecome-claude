# Changelog

Notable changes to ComeCome. This repo (`comecome-claude`) is **staging**; entries are promoted
to public `Come-come` (production) at release. Dates are ISO (YYYY-MM-DD).

## [Unreleased] — staging (targets v0.10.0)

Built and verified on staging (`schema_version` 5 → **6**); **not yet promoted to production**.

### Added — features (Sprints 3–10)
- **Clinical report hardening** — sleep-quality → next-day appetite/mood correlations; a
  "Clinical Summary" across the dashboard + all export surfaces.
- **Demographics** — `users.gender` + `date_of_birth` (guardian-only); child age on the dashboard.
- **Growth page** — optional height entry (`height_log`); the child weight page becomes "Growth"
  when percentiles are on. New `api/height.php`.
- **WHO growth percentiles** — `includes/growth-standards.php` (WHO 2006 + 2007 LMS) +
  `includes/percentiles.php` engine; percentile bands/zones/trajectory in the guardian dashboard +
  clinician reports (child chart stays clinical-overlay-free). WHO-first; CDC 2–19y is a planned
  follow-on. New `show_percentiles` toggle (default off).
- **Medication timing** — `medication_schedules` + `food_log.med_window` stamped server-side at
  insert; guardian timing config. Invisible to the child.
- **Nutrition-intelligence discovery** — `docs/roadmap/SPRINT-10-nutrition-discovery.md`: the
  concrete rule set/thresholds that make **Sprint 11** build-ready.

### Added — security ("Security & Deployment Foundations, Pt 2")
- Secure/HttpOnly/SameSite cookies; `session_regenerate_id` on login; idle timeout; default-`0000`
  PIN force-change guard + `scripts/reset-pin.php`.
- PIN brute-force **throttling/lockout** (`includes/throttle.php`, `login_attempts`).
- TLS/HTTPS 301 + **HSTS** (env-gated, + PHP backstop).
- **CSRF** on every state-changing POST + the 6 api endpoints (`includes/csrf.php`); login-page
  output escaping; **revocable guest tokens**.
- `.env`/secrets pattern (`includes/secrets.php`, `scripts/gen-key.php`) + base64 key container.
- **Opt-in libsodium field encryption** (`includes/crypto.php`) on scoped sensitive columns
  (`users.name`, `daily_checkin.notes`, `medications.name`/`dose`); `scripts/encrypt-backfill.php`
  (verify-first, idempotent). SQLCipher **deferred (VPS-only)** — infeasible on shared hosting.

### Added — tooling & deployment
- `tests/run.php` — single dependency-free regression entry point (298+ checks) + `tests/http_*_smoke.php`.
- `db/seed-demo.php` — 3-month anonymized demo dataset (`--reset` to re-seed).
- `config.local.php` per-deployment override (keeps `data.db` above the web root) +
  `config.local.php.example`; README "Production Hardening" steps.

### Changed
- `migrateDatabase()` advances to `schema_version` 6 (additive only; existing installs migrate cleanly).
- `sw.js` cache → `comecome-v0.9.5`.

### Fixed
- **schema.sql ↔ migration parity** — `db/schema.sql` now carries `daily_checkin.sleep_quality`
  so a fresh DB matches a v2-migrated one (it was previously only added by the migration). No
  functional change — fresh installs already gained the column via `migrateDatabase()`. Added the
  matching A1 parity assertion in `tests/run.php`.

### Design
- **Visual refresh** — off the old Duolingo-style palette onto the ComeCome system: tangerine
  `#E8722C` / plum `#7E3A5D` / leaf `#5E9A45` / oat `#FBF7EF`, Lexend + Atkinson Hyperlegible type,
  warm dark mode, calmer (reduced-motion-safe) celebration. Ships as a drop-in `comecome-theme.css`
  override (Phase 1) + remapped hardcoded JS/manifest/inline colors (Phase 2) + self-hosted fonts
  and SW precache (Phase 3). Fully revertible by removing the one `<link>`. Folding the theme into
  `custom.css` (making it canonical) is a deferred follow-on.

### Pending
- **Version reconcile** (`config.php` 0.9.1 / `sw.js` 0.9.5 → **v0.10.0**) at production promotion.
- Decisions i–v in `docs/roadmap/DECISIONS.md`; backlog in `.claude/SPRINT-PLAN_reconciled.md`.

## [0.9.1] — 2026-06-19
- Housekeeping release: consolidated the two repos, preserved roadmap/planning docs into
  `docs/roadmap/`, retired obsolete branches, archived as tags.

## [0.9.0] — 2026-03-08
- Sprints 0–2: bug fixes (duplicate food, favorites persistence), feature-visibility toggles, sleep tracking.
